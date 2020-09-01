<?php

require_once(__DIR__ . '/tree.php');

// This file represents a second attempt to implement a basic
// filesystem API (based on FUSE low-level api) that utilizes
// the crdt-tree (tree.php) for its data store.
//
// Basically a quick/dirty proof of concept.
//
// There is some design discussion here:
//  https://hackmd.io/1dBKrtkQTa656k070hxVvw?view
//
// There is some older related design discussion, starting at:
//  https://forum.safedev.org/t/filetree-crdt-for-safe-network/2833/41
//
// At init(), the filesystem creates a "forest" of trees that looks like:
//
// forest
//  - root
//  - fileinodes
//  - trash
//
// Some basics of the design:
//  * All paths in the filesystem are represented by nodes under
//    root, which can be of kind: dir, symlink, file-ref.
//  * inode entries for dir and symlink are stored in each nodes
//    metadata (beneath root)
//  * file-ref nodes store only [name, inode_id], where inode_id
//    identifies a node under /fileinodes.  In this way, multiple
//    file-ref can reference a single inode, which is how we support
//    hard-links.
//  * file inode entries are stored under /fileinodes.
//  * deleted (unlinked) nodes are moved to /trash
//  * ino numbers are split and used as tree identifiers.  
//    The first 16 bits represent the actor_id that created the inode
//    entry.  The remaining 48 bits represent the ino.
//  * There is no local storage.

// To help illustrate the above, here is an example of the 
// filesystem state after creating /home/bob/homework.txt on
// replica 1 and then adding a hardlink to homework.txt on replica 2.

// left-most digit of node ID represents actor_id.
// eg:  if node_id = 1234, actor_id = 1
//      if node_id = 2234, actor_id = 2
//      if node_id = 3234, actor_id = 3

/*
- null => forest
  - 1000 => {"name":"root","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
    - 1003 => {"name":"home","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
      - 1004 => {"name":"bob","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
        - 1005 => {"name":"projects","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
        - 1007 => {"name":"homework.txt","inode_id":1006}
        - 2000 => {"name":"homework-link.txt","inode_id":1006}
  - 1001 => {"name":"fileinodes","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
    - 1006 => {"size":0,"ctime":1598558928,"mtime":1598558928,"kind":"file", "links": 2, "content":null}
  - 1002 => {"name":"trash","size":0,"ctime":1598558928,"mtime":1598558928,"kind":"dir"}
*/


// metadata for tree nodes of type dir/symlink that live under forest/root (not files)
class fs_inode_meta {
    public $name;
    public $size;
    public $ctime;
    public $mtime;
    public $kind;

    function __construct(string $name, string $kind) {
        $this->name = $name;
        $this->size = 0;
        $this->ctime = time();
        $this->mtime = time();
        $this->kind = $kind;   // must be inode_kind::directory or symlink.
    }
}

// metadata for tree nodes of type file that live under forest/root (not dirs/symlinks)
class fs_ref_meta {
    public $name;
    public $inode_id;   // uuid, for looking up fs_inode_meta under /inodes
}

// metadata for tree nodes of type fileinode -- live under forest/fileinodes
class fs_inode_file_meta {
    public $size;
    public $ctime;
    public $mtime;
    public $kind;
    public $links;
    // public $xorname;  for now, store data in content.
    public $content;
}

// enum possible kinds of inode.
abstract class inode_kind
{
    const file = 'file';
    const directory = 'dir';
    const symlink = 'symlink';
}

class ino {

    static function combine(int $a, int $b): int {
        return $a<<48 | $b;
    }

    static function get_a(int $c): int {
        return $c >> 48;
    }

    static function get_b(int $c):int {
        return $c & 0xFFFFFFFFFFFF;
    }
}



// represents a filesystem.
// In a real implementation, The public methods would be called by fuse API.
//
// These prototype methods implement a simplified version of the fuse API
// because it is meant only for proving that the basic data structure
// design works, and will never actually be called by FUSE.
class filesystem {

    private $ino_counter = 0;   // next inode to create.

    // A crdt-tree replica, for applying tree operations.
    public $replica;

    function __construct(replica $r) {
        $this->replica = $r;
    }

    function new_ino() {
        return ino::combine($this->replica->id, $this->ino_counter++);
    }

    private function apply_merge_log_op(log_op_move $op) {

        // fs_inode_file_meta do not have 'name' attribute,
        // so no possibility of conflict.  no-op.
        if(!isset($op->metadata->name)) {
            return;
        }

        $name = $op->metadata->name;

        $matches = $this->children_with_name($op->parent_id, $name);
        if(count($matches) <= 1) {
            return;
        }

        $ops = [];
        foreach($matches as $conflict) {
            list($child_id, $tn) = $conflict;
//            $tn->meta->name = sprintf( '%s.conflict.%s.%s', $tn->meta->name, $tn->timestamp->actor_id(), $tn->timestamp->counter());
            $tn->meta->name = sprintf( '%s.conflict.%s', $tn->meta->name, $tn->timestamp->actor_id());
            $ops[] = new op_move($this->replica->tick(), $tn->parent_id, $tn->meta, $child_id);
        }
        $this->replica->apply_ops($ops);
    }

    public function apply_merge_log_ops(array $ops) {
        $this->replica->apply_log_ops($ops);

        foreach($ops as $op) {
            $this->apply_merge_log_op($op);
        }
    }

    private function apply_merge_log_op_lww(log_op_move $op) {

        // fs_inode_file_meta do not have 'name' attribute,
        // so no possibility of conflict.  no-op.
        if(!isset($op->metadata->name)) {
            return;
        }

        $name = $op->metadata->name;

        $matches = $this->children_with_name($op->parent_id, $name);
        if(count($matches) <= 1) {
            return;
        }

        $cb = function($a, $b) {
            $at = $a[1]->timestamp;
            $bt = $b[1]->timestamp;

            // reverse order, greatest to least.
            if( $at->eq($bt) ) {
                return 0;
            } else if ($at->lt($bt)) {
                return 1;
            } else {
                return -1;
            }
        };

        usort($matches, $cb);

        $ops = [];
        $cnt = 0;
        foreach($matches as $conflict) {
            if($cnt++ == 0) {
                continue;
            }
            list($child_id, $tn) = $conflict;
//            $tn->meta->name = sprintf( '%s.conflict.%s.%s', $tn->meta->name, $tn->timestamp->actor_id(), $tn->timestamp->counter());
            $tn->meta->name = sprintf( '%s.conflict.%s', $tn->meta->name, $tn->timestamp->actor_id());
            $ops[] = new op_move($this->replica->tick(), $tn->parent_id, $tn->meta, $child_id);
        }
        $this->replica->apply_ops($ops);
    }

    public function apply_merge_log_ops_lww(array $ops) {
        $this->replica->apply_log_ops($ops);

        foreach($ops as $op) {
            $this->apply_merge_log_op_lww($op);
        }
    }


    // Initialize filesystem
    public function init() {
        $r = $this->replica;

        $meta1 = new fs_inode_meta("root", inode_kind::directory);
        $meta2 = new fs_inode_meta("fileinodes", inode_kind::directory);
        $meta3 = new fs_inode_meta("trash", inode_kind::directory);

        // create root, fileinodes, and trash top-level nodes.
        $ops = [new op_move($r->tick(), null, $meta1, $root_id = $this->new_ino()),
                new op_move($r->tick(), null, $meta2, $fileinodes_id = $this->new_ino()),
                new op_move($r->tick(), null, $meta3, $trash_id = $this->new_ino()),
        ];

        $r->apply_ops($ops);
    }


    // Look up a directory entry by name and get its attributes.
    //
    // see https://john-millikin.com/the-fuse-protocol#FUSE_LOOKUP
    // 
    // In early versions of FUSE, fuse_entry_out::nodeid had to be non-zero.
    // Lookup failure was handled by ENOENT only. This restriction was lifted
    // in v7.6, so that a lookup response with nodeid == 0 meant a cacheable
    // lookup failure.
    //
    // Each successful lookup increments the node's reference count, 
    // which is decremented by FUSE_FORGET.
    //
    // returns ino.  0 means not found.
    public function lookup(string $path): int {
        // step 1.  Find tree node under /root matching path.
        $parts = explode('/', $path);
        $root = $this->root();
        list($node_id, $node) = $root;

        if($path == '/') {
            list($node_id, $node) = $root;
        } else {
            if($parts[0] == "") {
                array_shift($parts);
            }
            foreach($parts as $name) {
                $result = $this->child_by_name($node_id, $name, false);
                if( $result ) {
                    list($node_id, $node) = $result;
                } else {
                    return 0;
                }
            }
        }

        // note:  we do not increment lookup count.

        return $node_id;
    }

    /*
	 * Forget about an inode
	 *
	 * The nlookup parameter indicates the number of lookups
	 * previously performed on this inode.
	 *
	 * If the filesystem implements inode lifetimes, it is recommended
	 * that inodes acquire a single reference on each lookup, and lose
	 * nlookup references on each forget.
	 *
	 * The filesystem may ignore forget calls, if the inodes don't
	 * need to have a limited lifetime.
	 *
	 * On unmount it is not guaranteed, that all referenced inodes
	 * will receive a forget message.
     */    
    public function forget(int $ino, int $nlookup) {
        // note:  no lookup count in this impl.

        // note: in a real impl, we would de-allocate the inode here,
        // when ref-count drops to zero.
    }

    // Open a directory
    // note: stateless.
    public function opendir(int $ino_dir): ?int {
        return PHP_INT_MAX;  // stateless fh.
    }

    // Read a directory.
    // note: stateless, fh is ignored.
    public function readdir(int $ino_dir, int $fh, int $offset): ?array {
        $r = $this->replica;

        // 2. find child matching offset.
        $children = $r->state->tree->children($ino_dir);
        $child_id = @$children[$offset];
        if(!$child_id) {
            return null;
        }

        $tree_node = $this->tree_find($child_id);
        $m = $tree_node->meta;
        $ino = property_exists($m, 'inode_id') ? $m->inode_id : $child_id;
        return ['name' => $m->name, 'ino' => $ino];
    }

    // Release an open directory
    public function releasedir(int $ino_dir, ?int $fh) {
        // no-op, because stateless.
    }

    // Create a directory
    public function mkdir(int $parent_ino, string $name) {

        $r = $this->replica;
        $ops = [];

        // 2. find parent dir (under /root/)
        $inode_entry = $this->tree_find($parent_ino);

        // 3. create tree node under /root/../parent_id
        $fim = new fs_inode_meta($name, inode_kind::directory);
        $ops[] = new op_move($r->tick(), $parent_ino, $fim, $ino = $this->new_ino() );

        $r->apply_ops($ops);

        return $ino;
    }

    // Remove a directory
    public function rmdir(int $parent_ino, string $name) {

        $r = $this->replica;
        $ops = [];

        // 2. find child matching name
        list($ino, $node) = $this->child_by_name($parent_ino, $name);

        // 3. Ensure we have a directory
        if($node->meta->kind != inode_kind::directory) {
            throw new Exception("Not a directory");
        }

        // 4. Ensure dir is empty.
        $children = $r->state->tree->children($ino);
        if(count($children)) {
            throw new Exception("Directory not empty");
        }

        // 5. Generate op to move dir node to trash.
        list($trash_id) = $this->trash();
        $ops[] = new op_move($r->tick(), $trash_id, null, $ino);

        $r->apply_ops($ops);
    }


    // Rename a file, dir, or symlink
    public function rename(int $parent_ino, string $name, int $newparent_ino, string $newname) {

        $r = $this->replica;

        // 3. find child of parent that matches $name
        list($ino, $node) = $this->child_by_name($parent_ino, $name);
        $node->meta->name = $newname;

        // 4. move child to new location/name
        $ops[] = new op_move($r->tick(), $newparent_ino, $node->meta, $ino);

        $r->apply_ops($ops);

        return true;
    }

    // Create file node
    public function mknod(int $parent_ino, string $name, $mode) {

        if( $this->child_by_name($parent_ino, $name, false)) {
            throw new Exception("Already exists: $name");
        }

        $r = $this->replica;
        $ops = [];

        // 2. find parent dir (under /root/)
        $inode_entry = $this->tree_find($parent_ino);

        // 3. create tree node under /inodes/<x>
        $fim = new fs_inode_file_meta();
        $fim->size = 0;
        $fim->ctime = time();
        $fim->mtime = time();
        $fim->links = 1;
        $fim->kind = inode_kind::file;

        list($fileinodes_id) = $this->fileinodes($r->state->tree);
        $ops[] = new op_move($r->tick(), $fileinodes_id, $fim, $ino = $this->new_ino() );

        // 5. create tree entry under /root/../parent_id
        $frm = new fs_ref_meta();
        $frm->name = $name;
        $frm->inode_id = $ino;
        $ops[] = new op_move($r->tick(), $parent_ino, $frm, $this->new_ino() );

        $r->apply_ops($ops);

        return $ino;
    }

    // Open a file
    // note: stateless
    public function open(int $file_ino, $flags) {
        // no-op, stateless io.
        return PHP_INT_MAX;
    }

    // Write data to a file
    public function write(int $file_ino, int $fh, string $data) {
        $r = $this->replica;
        $ops = [];

        // 2. find tree node from inode_id
        $tree_node = $this->tree_find($file_ino);

        // 3. Append to content in metadata
        $meta = $tree_node->meta;
        $meta->content .= $data;

        // Generate op for updating the tree_node metadata
        $ops[] = new op_move($r->tick(), $tree_node->parent_id, $meta, $file_ino );

        $r->apply_ops($ops);
    }

    // Read data from a file
    public function read(int $file_ino, int $fh) {
        $r = $this->replica;

        $tree_node = $this->tree_find($file_ino);
        
        return $tree_node->meta->content;
    }

    // Flush any buffered data to file
    // note: stateless
    public function flush(int $file_ino) {
        // no-op, stateless io.
        return null;
    }

    // Release an open file
    // note: stateless
    public function release(int $file_ino) {
        // no-op, stateless io.
        return null;
    }

    // Create a hard link
    public function link(int $target_ino, int $parent_ino, string $name): int {

        $r = $this->replica;
        $ops = [];

        $tree_node = $this->tree_find($target_ino);

        $meta = $tree_node->meta;
        $meta->links ++;

        // Generate op for updating the target's metadata
        $ops[] = new op_move($r->tick(), $tree_node->parent_id, $meta, $target_ino );

        // 5. create tree entry under /root/../parent_id
        $frm = new fs_ref_meta();
        $frm->name = $name;
        $frm->inode_id = $target_ino;
        $ops[] = new op_move($r->tick(), $parent_ino, $frm, $this->new_ino() );

        $r->apply_ops($ops);

        return $target_ino;
    }

    // Remove (unlink) a file
    public function unlink(int $parent_ino, string $name) {

        $r = $this->replica;
        $t = $r->state->tree;
        $ops = [];

        // 2. find child of parent that matches $name
        list($node_id, $node) = $this->child_by_name($parent_ino, $name);

        // 4. decrement link count in /fileinodes inode entry.
        $inode = $this->tree_find($node->meta->inode_id);
        $meta = $inode->meta;
        $meta->links --;

        // 3. move child to trash.  (delete)
        list($trash_id) = $this->trash();
        $ops[] = new op_move($r->tick(), $trash_id, null, $node_id );

        // 5a. If link count is zero, move inode to trash.
        if($meta->links == 0) {
            $ops[] = new op_move($r->tick(), $trash_id, null, $node->meta->inode_id );
        } 
        // 5b. else, update link count.
        else {
            $ops[] = new op_move($r->tick(), $inode->parent_id, $meta, $node->meta->inode_id);
        }

        $r->apply_ops($ops);

        return true;
    }

    // Create a symlink (soft-link)
    public function symlink(string $link, int $parent_ino, string $name): int {

        $r = $this->replica;
        $ops = [];

        // 2. find parent dir (under /root/)
        $inode_entry = $this->tree_find($parent_ino);

        // 3. create tree node under /root/../parent_id
        $fim = new fs_inode_meta($name, inode_kind::symlink);
        $fim->link = $link;   // we are setting a non-existent property, gross!  but in PHP we can roll like that.
        $ops[] = new op_move($r->tick(), $parent_ino, $fim, $new_ino = $this->new_ino() );

        $r->apply_ops($ops);

        return $new_ino;
    }

    // read symlink
    public function readlink(int $ino): string {
        $r = $this->replica;

        // 2. find parent dir (under /root/)
        $inode_entry = $this->tree_find($ino);

        // 3. Ensure this node is a symlink
        if(!$inode_entry->meta->kind == inode_kind::symlink) {
            throw new Exception("Inode is not a symlink. $ino");
        }

        return $inode_entry->meta->link;
    }

    // prints current state of the filesystem
    public function print_current_state(string $description="") {
        $title = $description ? "fs state after: $description" : 'current filesystem state';

        echo "\n\n------- $title -------\n";
        print_tree($this->replica->state->tree, true);
        echo "------- end state -------\n";
    }

    // return crdt-tree node for a given ID.
    private function tree_find(int $node_id) {
        $node = $this->replica->state->tree->find($node_id);
        if(!$node) {
            throw new Exception("Missing entry for $node_id");
        }
        return $node;
    }

    // Get child of a directory by name.
    private function children_with_name(?int $parent_id, string $name, bool $throw=true): ?array {
    
        $matches = [];
        $t = $this->replica->state->tree;
        foreach($t->children($parent_id) as $child_id) {
            $node = $t->find($child_id);
            if($node && $node->meta->name == $name) {
                $matches[] = [$child_id, $node];
            }
        }
        if(count($matches) == 0 && $throw) {
            throw new Exception("Not found: $name");
        }
        return $matches;
    }


    // Get child of a directory by name.
    private function child_by_name(?int $parent_id, string $name, bool $throw=true): ?array {
        $matches = $this->children_with_name($parent_id, $name, false);

        switch(count($matches)) {
            case 1: return $matches[0];
            default:
                if($throw) {
                    $msg = count($matches) == 0 ? "Not found: $name" : "Found too many: $name";
                    throw new Exception($msg);
                } else {
                    return null;
                }
        }
    }

    // retrieve a top-level forest node (root, fileinodes, or trash)
    private function toplevel($name) {
        return $this->child_by_name(null, $name);
    }

    // retrieve root, fileinodes and trash top level forest nodes.
    private function root() { return $this->toplevel("root"); }
    private function fileinodes() { return $this->toplevel("fileinodes"); }
    private function trash() { return $this->toplevel("trash"); }

    // true if replica stat's are equal and local inode counts match.
    public function is_equal(filesystem $other) {
        return $this->replica->state == $other->replica->state;
    }
}


function test_fs_link_and_unlink() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // display state
    $fs->print_current_state("Initialized");

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /home/bob
    $ino_home = $fs->mkdir($ino_root, "home" );
    $ino_bob = $fs->mkdir($ino_home, "bob" );

    // create /home/bob/homework.txt and hard-link homework-link.txt
    $ino_homework = $fs->mknod($ino_bob, "homework.txt", 'c' );
    $fs->link($ino_homework, $ino_bob, "homework-link.txt");

    // display state
    $fs->print_current_state("Hard-link created");

    // unlink homework.txt and display state
    $fs->unlink($ino_bob, "homework.txt");
    $fs->print_current_state("Original file removed");

    // unlink homework-link.txt and display state
    $fs->unlink($ino_bob, "homework-link.txt");
    $fs->print_current_state("Hard-linked file removed");
}

function test_fs_readdir() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /home/bob/projects
    $ino_home = $fs->mkdir($ino_root, "home" );
    $ino_bob = $fs->mkdir($ino_home, "bob" );
    $ino_projects = $fs->mkdir($ino_bob, "projects" );

    // create /home/bob/homework.txt and hard-link homework-link.txt
    $ino_homework = $fs->mknod($ino_bob, "homework.txt", 'c' );
    $fs->link($ino_homework, $ino_bob, "homework-link.txt");

    $fs->print_current_state("Home dir created");
    echo "\n\n";

    $ino_bob2 = $fs->lookup("/home/bob");
    $fh = $fs->opendir($ino_bob2);
    $cnt = 0;
    while($entry = $fs->readdir($ino_bob2, $fh, $cnt++)) {
        echo json_encode($entry) . "\n";
    }
    $fs->releasedir($ino_bob2, $fh);
}

function test_fs_write_and_read() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /etc
    $ino_etc = $fs->mkdir($ino_root, "etc" );

    // create /etc/filetree.conf and write some lines to it.
    $ino_conf = $fs->mknod($ino_etc, "filetree.conf", 'c' );
    $fh = $fs->open($ino_conf, 'w');
    $fs->write($ino_conf, $fh, "line1\n");
    $fs->write($ino_conf, $fh, "line2\n");
    $fs->write($ino_conf, $fh, "line3\n");
    $fs->flush($ino_conf, $fh);
    $fs->release($ino_conf, $fh);

    $fs->print_current_state("filetree.conf written");
    echo "\n\n";

    $fh = $fs->open($ino_conf, 'r');
    echo "-- contents of /etc/filetree.conf --\n";
    echo $fs->read($ino_conf, $fh);
    $fs->release($ino_conf);
    echo "\n\n";
}

function test_fs_rename() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /home/bob/projects
    $ino_home = $fs->mkdir($ino_root, "home" );
    $ino_bob = $fs->mkdir($ino_home, "bob" );
    $ino_projects = $fs->mkdir($ino_bob, "projects" );

    // create /home/bob/homework.txt and hard-link homework-link.txt
    $ino_homework = $fs->mknod($ino_bob, "homework.txt", 'c' );
    $fs->link($ino_homework, $ino_bob, "homework-link.txt");

    $fs->print_current_state("Home dir created");
    echo "\n\n";

    $fs->rename($ino_bob, "homework.txt", $ino_bob, "renamed.txt");
    $fs->rename($ino_bob, "homework-link.txt", $ino_projects, "moved_and_renamed.txt");
    $fs->rename($ino_bob, "projects", $ino_home, "projects_moved");

    $fs->print_current_state("Files and directory renamed/moved");
}

function test_fs_symlink() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /home/bob/projects
    $ino_home = $fs->mkdir($ino_root, "home" );
    $ino_bob = $fs->mkdir($ino_home, "bob" );
    $ino_projects = $fs->mkdir($ino_bob, "projects" );

    // create /home/bob/homework.txt and hard-link homework-link.txt
    $ino_homework = $fs->mknod($ino_bob, "homework.txt", 'c' );

    $inos = [];
    $inos[] = $fs->symlink("homework.txt", $ino_bob, "rel-file-link.txt");
    $inos[] = $fs->symlink("/home/bob/homework.txt", $ino_bob, "abs-file-link.txt");
    $inos[] = $fs->symlink("../homework.txt", $ino_bob, "rel-file-link.txt");
    $inos[] = $fs->symlink("non-existent.txt", $ino_bob, "broken-file-link.txt");

    $inos[] = $fs->symlink("projects", $ino_bob, "rel-dir-link");
    $inos[] = $fs->symlink("..", $ino_projects, "parent-dir-link");

    $fs->print_current_state("Symlinks created");

    echo "\n\n-- reading symlinks --\n";
    foreach($inos as $ino) {
        printf("%s - %s\n", $ino, $fs->readlink($ino));
    }
}

function test_fs_rmdir() {
    // init filesystem
    $fs = new filesystem(new replica(1));
    $fs->init();

    // get ino for /
    $ino_root = $fs->lookup("/");

    // create /home/bob/projects
    $ino_home = $fs->mkdir($ino_root, "home" );
    $ino_bob = $fs->mkdir($ino_home, "bob" );
    $ino_projects = $fs->mkdir($ino_bob, "projects" );

    // create /home/bob/homework.txt and hard-link homework-link.txt
    $ino_homework = $fs->mknod($ino_bob, "homework.txt", 'c' );

    $fs->rmdir($ino_bob, "projects");

    $fs->print_current_state("removed directory projects");

    echo "\n";
    try {
        $fs->rmdir($ino_home, "bob");
        echo "Error: rmdir did not throw exception when removing non-empty directory.\n";
    }
    catch(Exception $e) {
        echo "success: rmdir threw exception when removing non-empty directory.\n";
    }
}


// This test tries to sync two filesystems only by apply OpMove
// from replica1 to replica2.
//
// That doesn't (presently) work because replica1 also has some
// local state that does not get re-created by replica2 when
// applying the ops.
//
// Basically, this test demonstrates that the present design does
// not work for syncing two filesystem replicas, and so we are
// back to the drawing board.
function test_fs_replicas() {
    // init filesystem, replica 1.
    $fs1 = new filesystem(new replica(1));
    $fs1->init();

    // init filesystem, replica 2.
    $fs2 = new filesystem(new replica(2));

    // get ino for /
    $ino_root = $fs1->lookup("/");

    // create /home/bob
    $ino_home = $fs1->mkdir($ino_root, "home" );
    $ino_bob = $fs1->mkdir($ino_home, "bob" );

    $fs2->replica->apply_log_ops($fs1->replica->state->log_op_list);

    if($fs1->is_equal($fs2)) {
        echo "\n== Pass!  replica1 and replica2 filesystems match. ==\n";
    } else {
        echo "\n== Fail!  replica1 and replica2 filesystems do not match. ==\n";

        $fs1->print_current_state("created /home/bob.  (replica1 state)");
        $fs2->print_current_state("created /home/bob.  (replica2 state)");
    }
}

function test_fs_filename_collision() {
    // init filesystem, replica 1.
    $fs1 = new filesystem(new replica(1));
    $fs1->init();
    $fs1->print_current_state("initialized. (replica1");

    // init filesystem, replica 2.
    $fs2 = new filesystem(new replica(2));

    // get ino for /
    $ino_root = $fs1->lookup("/");

    // create /home/bob
    $ino_tmp = $fs1->mkdir($ino_root, "tmp" );

    $fs2->apply_merge_log_ops($fs1->replica->state->log_op_list);

    $ino_f1 = $fs1->mknod($ino_tmp, "file1.txt", 'c' );
    $fh = $fs1->open($ino_f1, 'w');
    $fs1->write($ino_f1, $fh, "hello from replica1\n");
    $fs1->flush($ino_f1, $fh);
    $fs1->release($ino_f1, $fh);

    $fs1->print_current_state("created /tmp/file1.txt.  (replica1)");

    $ino_f1 = $fs2->mknod($ino_tmp, "file1.txt", 'c' );
    $fh = $fs2->open($ino_f1, 'w');
    $fs2->write($ino_f1, $fh, "hello from replica2\n");
    $fs2->flush($ino_f1, $fh);
    $fs2->release($ino_f1, $fh);

    $fs2->print_current_state("created /tmp/file1.txt.  (replica2)");

    // uncomment to test concurrent creation of conflict filename.
    // $fs1->mknod($ino_tmp, "file1.txt.conflict.1", 'c' );

    $fs2->apply_merge_log_ops($fs1->replica->state->log_op_list);
    $fs1->apply_merge_log_ops($fs2->replica->state->log_op_list);

    $fs1->print_current_state("merged ops from replica2. (replica1");

    // apply conflict resolution ops generated by last merge.
    $fs1->apply_merge_log_ops($fs2->replica->state->log_op_list);
    $fs2->apply_merge_log_ops($fs1->replica->state->log_op_list);

    $conflict_again = false;
    if( $conflict_again ) {
        $fs1->mknod($ino_tmp, "file1.txt", 'c' );
        $fs2->mknod($ino_tmp, "file1.txt", 'c' );

        $fs1->apply_merge_log_ops($fs2->replica->state->log_op_list);
        $fs2->apply_merge_log_ops($fs1->replica->state->log_op_list);
    }

    if($fs1->is_equal($fs2)) {
        echo "\n== Pass!  replica1 and replica2 filesystems match. ==\n";
    } else {
        echo "\n== Fail!  replica1 and replica2 filesystems do not match. ==\n";
    }
    $fs1->print_current_state("created /home/bob.  (replica1 state)");
    $fs2->print_current_state("created /home/bob.  (replica2 state)");
}


function test_fs_filename_collision_lww() {
    // init filesystem, replica 1.
    $fs1 = new filesystem(new replica(1));
    $fs1->init();
    $fs1->print_current_state("initialized. (replica1");

    // init filesystem, replica 2.
    $fs2 = new filesystem(new replica(2));

    // get ino for /
    $ino_root = $fs1->lookup("/");

    // create /home/bob
    $ino_tmp = $fs1->mkdir($ino_root, "tmp" );

    $fs2->apply_merge_log_ops($fs1->replica->state->log_op_list);

    $ino_f1 = $fs1->mknod($ino_tmp, "file1.txt", 'c' );
    $fh = $fs1->open($ino_f1, 'w');
    $fs1->write($ino_f1, $fh, "hello from replica1\n");
    $fs1->flush($ino_f1, $fh);
    $fs1->release($ino_f1, $fh);

    $fs1->print_current_state("created /tmp/file1.txt.  (replica1)");

    $ino_f1 = $fs2->mknod($ino_tmp, "file1.txt", 'c' );
    $fh = $fs2->open($ino_f1, 'w');
    $fs2->write($ino_f1, $fh, "hello from replica2\n");
    $fs2->flush($ino_f1, $fh);
    $fs2->release($ino_f1, $fh);

    $fs2->print_current_state("created /tmp/file1.txt.  (replica2)");

    // uncomment to test concurrent creation of conflict filename.
    $fs1->mknod($ino_tmp, "file1.txt.conflict.1", 'c' );

    $fs2->apply_merge_log_ops_lww($fs1->replica->state->log_op_list);
    $fs1->apply_merge_log_ops_lww($fs2->replica->state->log_op_list);

    $fs1->print_current_state("merged ops from replica2. (replica1");

    // apply conflict resolution ops generated by last merge.
    $fs1->apply_merge_log_ops_lww($fs2->replica->state->log_op_list);
    $fs2->apply_merge_log_ops_lww($fs1->replica->state->log_op_list);

    $conflict_again = false;
    if( $conflict_again ) {
        $fs1->mknod($ino_tmp, "file1.txt", 'c' );
        $fs2->mknod($ino_tmp, "file1.txt", 'c' );

        $fs1->apply_merge_log_ops_lww($fs2->replica->state->log_op_list);
        $fs2->apply_merge_log_ops_lww($fs1->replica->state->log_op_list);
    }

    if($fs1->is_equal($fs2)) {
        echo "\n== Pass!  replica1 and replica2 filesystems match. ==\n";
    } else {
        echo "\n== Fail!  replica1 and replica2 filesystems do not match. ==\n";
    }
    $fs1->print_current_state("created /home/bob.  (replica1 state)");
    $fs2->print_current_state("created /home/bob.  (replica2 state)");
}



function fs_main() {
    $test = @$GLOBALS['argv'][1];

    $tests = [
        'test_fs_link_and_unlink',
        'test_fs_readdir',
        'test_fs_write_and_read',
        'test_fs_rename',
        'test_fs_symlink',
        'test_fs_rmdir',
        'test_fs_replicas',
        'test_fs_filename_collision',
        'test_fs_filename_collision_lww',
    ];

    if(in_array($test, $tests)) {
        $test();
    } else {
        fs_print_help($tests);
    }
}


function fs_print_help($tests) {
    $tests_buf = '';
    foreach($tests as $t) {
        $tests_buf .= "   $t\n";
    }

    echo <<< END
Usage: filesystem.php <test>

<test> can be any of:
$tests_buf

END;
}

if($_SERVER["SCRIPT_FILENAME"] == basename(__FILE__)) {
    fs_main();
}
