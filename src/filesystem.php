<?php

require_once(__DIR__ . '/tree.php');

/*

format: parent_id, meta, child_id.    (where parent_id and child_id are uuid)

- null, (name<root>, inode<(type<dir>, size<0>, ctime<x>, mtime<x>)>), 1
  - 1, (name<file1>, inode_uuid<123>), 555
  - 1, (name<file2>, inode_uuid<123>), 556
- null, (name<fileinodes>), 2 
  - 2, (type<file>, size<510>, ctime<x>, mtime<x>, xorname<hash>), 123
- null, (name<trash>), 3

plus, we have a purely local hashmap/dictionary/index of uuid => local_inode_entry:

123 ---> (ino<5>, uuid<123>, lookup_cnt<2>, is_file<true>)

and another that is ino => local_inode_entry:

5 ---> (ino<5>, uuid<123>, lookup_cnt<2>, is_file<true>)

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
    // public $xorname;  for now, store data in content.
    public $content;
}

// represents inode information that is stored locally, not in the crdt-tree.
class fs_inode_local {
    public $ino;         // u64 inode identifier.
    public $tree_id;     // uuid inode identifier.
    public $ref_count;
    public $links;
    public $is_file;
}

// enum possible kinds of inode.
abstract class inode_kind
{
    const file = 'file';
    const directory = 'dir';
    const symlink = 'symlink';
}

// represents a filesystem.
// In a real implementation, The public methods would be called by fuse API.
//
// These prototype methods implement a simplified version of the fuse API
// because it is meant only for proving that the basic data structure
// design works, and will never actually be called by FUSE.
class filesystem {

    // a map of u64 to &fs_inode_local for storing local inode data such
    // as read-only ref counts that does not need to be shared with other
    // replicas.  
    private $ino_inodes_local = [];

    // a map of uuid to &fs_inode_local
    private $uuid_inodes_local = [];

    private $ino_counter = 2;   // last created inode.

    // A crdt-tree replica, for applying tree operations.
    private $replica;

    function __construct(replica $r) {
        $this->replica = $r;
    }

    // Initialize filesystem
    public function init() {
        $r = $this->replica;

        $meta1 = new fs_inode_meta("root", inode_kind::directory);
        $meta2 = new fs_inode_meta("fileinodes", inode_kind::directory);
        $meta3 = new fs_inode_meta("trash", inode_kind::directory);

        // create root, fileinodes, and trash top-level nodes.
        $ops = [new op_move($r->tick(), null, $meta1, $root_id = new_id()),
                new op_move($r->tick(), null, $meta2, $fileinodes_id = new_id()),
                new op_move($r->tick(), null, $meta3, $trash_id = new_id()),
        ];

        $r->apply_ops($ops);

        $this->add_fs_inode_local($root_id, inode_kind::directory);
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
                $result = $this->child_by_name($node_id, $name);
                if( $result ) {
                    list($node_id, $node) = $result;
                } else {
                    return 0;
                }
            }
        }

        // step 2.  Find node local inode data matching $node_id
        $tn = $this->uuid_inodes_local[$node_id];
        if(!$tn) {
            throw new Exception("Filesystem out of sync!  local inode entry not found!");
        }

        // increment ref count
        $tn->ref_count ++;

        return $tn->ino;
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

        // 1. find directory tree ID, given ino
        $parent_id = $this->ino_to_tree_id($ino_dir);

        // 2. find child matching offset.
        $children = $r->state->tree->children($parent_id);
        $child_id = @$children[$offset];
        if(!$child_id) {
            return null;
        }

        $tree_node = $r->state->tree->find($child_id);
        $m = $tree_node->meta;
        $inode_id = property_exists($m, 'inode_id') ? $m->inode_id : $child_id;
        $local_entry = $this->uuid_inodes_local[$inode_id];
        return ['name' => $m->name, 'ino' => $local_entry->ino];
    }

    // Release an open directory
    public function releasedir(int $ino_dir, ?int $fh) {
        // no-op, because stateless.
    }

    // Create a directory
    public function mkdir(int $parent_ino, string $name) {

        $r = $this->replica;
        $ops = [];

        // 1. find parent_id from parent_ino.
        $parent_id = $this->ino_to_tree_id($parent_ino);

        // 2. find parent dir (under /root/)
        $inode_entry = $r->state->tree->find($parent_id);
        if(!$inode_entry) {
            throw new Exception("Missing inode entry for $parent_id");
        }

        // 3. create tree node under /root/../parent_id
        $fim = new fs_inode_meta($name, inode_kind::directory);
        $ops[] = new op_move($r->tick(), $parent_id, $fim, $new_inode_id = new_id() );

        // 4. create/add fs_inode_local
        $ino = $this->add_fs_inode_local($new_inode_id, $fim->kind);

        $r->apply_ops($ops);

        return $ino;
    }

    // Rename a file, dir, or symlink
    public function rename(int $parent_ino, string $name, int $newparent_ino, string $newname) {

        $r = $this->replica;

        // 1. find parent_id from parent_ino.
        $parent_id = $this->ino_to_tree_id($parent_ino);

        // 2. find newparent_id from newparent_ino
        // optimization todo: check if the ino's are the same, to avoid 2nd lookup.
        $newparent_id = $this->ino_to_tree_id($newparent_ino);

        // 3. find child of parent that matches $name
        $result = $this->child_by_name($parent_id, $name);
        if(!$result) {
            throw new Exception("no such file: $name");
        }
        list($node_id, $node) = $result;
        $node->meta->name = $newname;

        // 4. move child to new location/name
        $ops[] = new op_move($r->tick(), $newparent_id, $node->meta, $node_id);

        $r->apply_ops($ops);

        return true;
    }

    // Create file node
    public function mknod(int $parent_ino, string $name, $mode) {

        $r = $this->replica;
        $ops = [];

        // 1. find parent_id (uuid of fs_inode_entry) from parent_ino.
        $parent_id = $this->ino_to_tree_id($parent_ino);

        // 2. find parent dir (under /root/)
        $inode_entry = $r->state->tree->find($parent_id);
        if(!$inode_entry) {
            throw new Exception("Missing inode entry for $parent_id");
        }

        // 3. create tree node under /inodes/<x>
        $fim = new fs_inode_file_meta();
        $fim->size = 0;
        $fim->ctime = time();
        $fim->mtime = time();
        $fim->kind = inode_kind::file;

        list($fileinodes_id) = $this->fileinodes($r->state->tree);
        $ops[] = new op_move($r->tick(), $fileinodes_id, $fim, $new_inode_id = new_id() );

        // 4. create/add fs_inode_local
        $ino = $this->add_fs_inode_local($new_inode_id, $fim->kind);

        // 5. create tree entry under /root/../parent_id
        $frm = new fs_ref_meta();
        $frm->name = $name;
        $frm->inode_id = $new_inode_id;
        $ops[] = new op_move($r->tick(), $parent_id, $frm, new_id() );

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

        // 1. find inode_id from file_ino.
        $inode_id = $this->ino_to_tree_id($file_ino);

        $tree_node = $r->state->tree->find($inode_id);
        $meta = $tree_node->meta;
        $meta->content .= $data;

        // Generate op for updating the tree_node metadata
        $ops[] = new op_move($r->tick(), $tree_node->parent_id, $meta, $inode_id );

        $r->apply_ops($ops);
    }

    // Read data from a file
    public function read(int $file_ino, int $fh) {
        $r = $this->replica;

        // 1. find inode_id from file_ino.
        $inode_id = $this->ino_to_tree_id($file_ino);

        $tree_node = $r->state->tree->find($inode_id);
        
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

        // 1. find parent_id (uuid of fs_inode_entry) from parent_ino.
        $parent_id = $this->ino_to_tree_id($parent_ino);

        // 2. find target id.
        $target_local_inode = $this->ino_to_tree_entry($target_ino);
        $target_id = $target_local_inode->tree_id;

        // 5. create tree entry under /root/../parent_id
        $frm = new fs_ref_meta();
        $frm->name = $name;
        $frm->inode_id = $target_id;
        $ops[] = new op_move($r->tick(), $parent_id, $frm, new_id() );

        $r->apply_ops($ops);

        // 6. increment links count
        $target_local_inode->links ++;

        return $target_ino;
    }

    // Remove (unlink) a file
    public function unlink(int $parent_ino, string $name) {

        $r = $this->replica;
        $t = $r->state->tree;
        $ops = [];

        // 1. find parent_id (uuid of fs_inode_entry) from parent_ino.
        $parent_id = $this->ino_to_tree_id($parent_ino);

        // 2. find child of parent that matches $name
        $result = $this->child_by_name($parent_id, $name);
        if(!$result) {
            throw new Exception("no such file: $name");
        }
        list($node_id, $node) = $result;

        // 3. move child to trash.  (delete)
        list($trash_id) = $this->trash();
        $ops[] = new op_move($r->tick(), $trash_id, null, $node_id );

        // 4. decrement link count in local inode entry.
        $inode_local =& $this->uuid_inodes_local[$node->meta->inode_id];
        $inode_local->links --;

        // 5. If link count is zero, do some cleanup.
        if($inode_local->links == 0) {
            // remove local inode indexes.
            unset($this->uuid_inodes_local[$inode_local->tree_id]);
            unset($this->ino_inodes_local[$inode_local->ino]);

            // for files only, remove /fileinodes/x entry.
            if($inode_local->is_file) {
                $ops[] = new op_move($r->tick(), $trash_id, null, $inode_local->tree_id );
            }
        }

        $r->apply_ops($ops);

        return true;
    }

    // prints current state of the filesystem
    public function print_current_state(string $description="") {
        $title = $description ? "fs state after: $description" : 'current filesystem state';

        echo "\n\n------- $title -------\n";
        print_tree($this->replica->state->tree, true);
        echo "\nino --> fs_inode_local:\n";
        foreach($this->ino_inodes_local as $k => $v) {
            printf("%s => %s\n", $k, json_encode($v));
        }
        echo "\nuuid --> fs_inode_local:\n";
        foreach($this->uuid_inodes_local as $k => $v) {
            printf("%s => %s\n", $k, json_encode($v));
        }
        echo "------- end state -------\n";
    }

    // creates fs_inode_local entry and adds it to indexes.
    private function add_fs_inode_local($tree_id, string $kind) {
        // 1. Create fs_inode_local
        $local = new fs_inode_local();
        $local->ino = ++$this->ino_counter;
        $local->tree_id = $tree_id;
        $local->ref_count = 1;
        $local->links = 1;
        $local->is_file = $kind == inode_kind::file;

        // 2. Add uuid --> &fs_inode_local index
        $this->uuid_inodes_local[$local->tree_id] = &$local;

        // 3. Add ino  --> &fs_inode_local_index
        $this->ino_inodes_local[$local->ino] = &$local;

        return $local->ino;
    }

    // Get child of a directory by name.
    private function child_by_name($parent_id, $name): ?array {
        $t = $this->replica->state->tree;
        foreach($t->children($parent_id) as $child_id) {
            $node = $t->find($child_id);
            if($node && $node->meta->name == $name) {
                return [$child_id, $node];
            }
        }
        return null;
    }

    // retrieve a top-level forest node (root, fileinodes, or trash)
    private function toplevel($name) {
        $result = $this->child_by_name(null, $name);
        if(!$result) {
            throw new Exception("$name not found!");
        }
        return $result;
    }

    // retrieve root, fileinodes and trash top level forest nodes.
    private function root() { return $this->toplevel("root"); }
    private function fileinodes() { return $this->toplevel("fileinodes"); }
    private function trash() { return $this->toplevel("trash"); }

    // given a local ino, retrieve corresponding tree node
    private function ino_to_tree_entry(int $ino) {
        // 1. find parent_id (uuid of fs_inode_entry) from parent_ino.
        $local_inode = @$this->ino_inodes_local[$ino];
        if(!$local_inode) {
            throw new Exception("ino not found: $ino");
        }
        return $local_inode;
    }

    // given a local ino, retrieve corresponding tree node ID
    private function ino_to_tree_id(int $ino) {
        $entry = $this->ino_to_tree_entry($ino);
        return $entry->tree_id;
    }
}


function test_fs_link_and_unlink() {
    // init filesystem
    $fs = new filesystem(new replica());
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
    $fs = new filesystem(new replica());
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
    $fs = new filesystem(new replica());
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
    $fs = new filesystem(new replica());
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




function fs_main() {
    $test = @$GLOBALS['argv'][1];

    $tests = [
        'test_fs_link_and_unlink',
        'test_fs_readdir',
        'test_fs_write_and_read',
        'test_fs_rename',
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
