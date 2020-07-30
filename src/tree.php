<?php

// This code is a first attempt to implement the CRDT Tree algorithm
// described in the paper "A highly-available move operation for 
// replicated trees and distributed filesystems" by Kleppman et al.
//    Link:  https://martin.kleppmann.com/papers/move-op.pdf
//
// Many/most comments in the code are taken directly from the paper
// as the authors describe the algo.
//
// This first implementation defines the tree data structure
// as an unordered list (set) of triples.  This is not efficient
// but it most closely follows the formally proven algo presented
// in the paper.
//
// Presently, this file does not match the usage pattern of
// the other crdt algo's in this directory.  This file is self
// contained, and can be directly executed to run tests.
//
// As this is a quick/dirty experiment, no attempt is made at 
// encapsulation.  All properties are public, etc.

/*****************************************
 * Begin Formal Algorithm
 *****************************************/

// ------ Define Data Structures ----------

// At time $timestamp, $child_id is moved to be a child of $parent_id.
// Old location doesn't matter.
// If child_id does not exist, it is created.
//
// In a filesystem, parent and child are inodes of a directory
// and and file within it, respectively.  The metadata contains
// the filename of the child.  Thus a file with inode c can be renamed
// by performing a Move t p m c, where the new parent directory p is
// the inode of the existing parent (unchanged), but the metadata
// m contains the new filename.
//
// When users want to make changes to the tree on their local replica
// they generate new Move t p m c operations for these changes, and
// apply these operations using the algorithm described in the rest of
// this section.
class op_move {
    public $timestamp;   // clock_interface (lamport clock + actor)
    public $parent_id;   // globally unique, eg uuid
    public $metadata;
    public $child_id;    // globally unique, eg uuid

    function __construct($t, $p, $m, $c) {
        $this->timestamp = $t;
        $this->parent_id = $p;
        $this->metadata = $m;
        $this->child_id = $c;
    }

    static function from_log_op_move(log_op_move $log): op_move {
        return new op_move($log->timestamp,
                           $log->parent_id,
                           $log->metadata,
                           $log->child_id);
    }
}

// When a replica applies a Move operation to its tree it
// also records a corresponding LogMove operation in its log.
// The t, p, m, and c fields are taken directly from the Move
// record while the oldp field is filled in based on the
// state of the tree before the move.  If c did not exist
// in the tree, oldp is set to None. Else oldp records the
// previous parent metadata of c: if there exist p' and m'
// such that (p', m', c') E tree, then oldp is set to Some(p', m').
// The get_parent() function implements this.
class log_op_move {
    public $timestamp;   // clock_interface (lamport clock + actor)
    public $parent_id;   // globally unique, eg uuid
    public $metadata;
    public $child_id;    // globally unique, eg uuid
    public $oldp;        // None/null or tree_node

    function __construct(op_move $op, $oldp = null) {
        $this->timestamp = $op->timestamp;
        $this->parent_id = $op->parent_id;
        $this->metadata = $op->metadata;
        $this->child_id = $op->child_id;
        $this->oldp = $oldp;
    }

    // for testing. not part of crdt-tree algo.
    function is_equal(log_op_move $other): bool {
        return $this == $other;
    }
}

// represents state of a single replica
// at a given moment.
//
// consists of a list of log operations
// and a tree.
//
// defined in paper as:
//   type_synonym ('t, 'n, 'm) state = ('t, 'n, 'm) log_op list x ('n x 'm x 'n) set
class state {
    public $log_op_list = [];  // a list of LogMove in descending timestamp order.
    public $tree;

    function __construct(array $ops = [], tree $tree = null) {
        $this->log_op_list = $ops;
        $this->tree = $tree == null ? new tree() : $tree;
    }

    function add_log_entry(log_op_move $entry) {
        // add at beginning of array
        array_unshift($this->log_op_list, $entry);
    }

    // removes log entries before a given timestamp.
    // not part of crdt-tree algo.
    function truncate_log_before(clock_interface $timestamp): bool {
        $a = &$this->log_op_list;
        $truncated = false;

        foreach($this->log_op_list as $key => $v) {
            if($v->timestamp->lt($timestamp)) {
                unset($this->log_op_list[$key]);
                $truncated = true;
            }
        }

        return $truncated;
    }

    // for testing. not part of crdt-tree algo.
    function is_equal(state $other): bool {
        return $this->log_op_list == $other->log_op_list &&
               $this->tree->is_equal($other->tree);
    }

    // for testing. not part of crdt-tree algo.
    function check_log_is_descending() {
        $i = 0;
        for($i = 0; $i < count($this->log_op_list)-1; $i ++) {
            $first = $this->log_op_list[$i];
            $second = $this->log_op_list[$i+1];

            if( !$first->timestamp->gt($second->timestamp) ) {
                throw new Exception("Log not in descending timestamp order!");
            }
        }
        return true;
    }
}

// Represents a parent, meta, child triple
// that is stored in an unordered set in a tree 
class tree_node {
    public $parent_id;
    public $meta;
    // note: child_id is stored only as a map key in tree.

    function __construct($parent_id, $meta) {
        $this->parent_id = $parent_id;
        $this->meta = $meta;
    }

    function is_equal(tree_node $other) {
        return $this->parent_id === $other->parent_id &&
               $this->meta === $other->meta;
    }

}

// Represents a tree as a set (unordered list)
// of (parent, meta child) triples.
//
// Presented in paper as:
//   ('n x 'm x 'n)
class tree {
    public $triples = [];
    public $children = [];  // parent_id => [child_id => true].  optimization.

    // helper for removing a triple based on child_id
    function rm_child($child_id) {
        $t = @$this->triples[$child_id];
        if($t) {
            unset($this->children[$t->parent_id][$child_id]);
            unset($this->triples[$child_id]);
        }
    }

    // adds a node to the tree
    function add_node($child_id, tree_node $tt) {
        $this->triples[$child_id] = $tt;
        $this->children[$tt->parent_id][$child_id] = true;
    }

    // returns matching node, or null.
    function find($child_id): ?tree_node {
        return @$this->triples[$child_id];
    }

    // returns children (IDs) of a given parent node.
    // useful for walking tree.
    // not used by crdt algo.
    function children($parent_id): ?array {
        $list = @$this->children[$parent_id];
        return $list ? array_keys($list) : [];
    }

    // walks tree and calls callback fn for each node.
    // not used by crdt algo.
    function walk($parent_id, $callback) {
        $callback($parent_id);
        $children = $this->children($parent_id);
        foreach($children as $c) {
            $this->walk($c, $callback);
        }
    }

    // test for equality between two trees.
    // not used by crdt algo.
    function is_equal(tree $other): bool {
        // We must treat the triples array as an unordered set
        // (where the two sets are equal even if values are present
        // in a different order).
        // Therefore, we cannot simply check if array_values()
        // for each set is equal.
        foreach($this->triples as $k => $t) {
            $o = @$other->triples[$k];
            if(!$o || !$t->is_equal($o)){
                return false;
            }
        }
        foreach($other->triples as $k => $t) {
            $o = @$this->triples[$k];
            if(!$o || !$t->is_equal($o)){
                return false;
            }
        }
        return true;
    }
}

// ------ Operations / Functions ----------

// finds parent of a given child node in a tree.
// returns [parent_id, meta]
function get_parent(tree $tree, $child_id):? tree_node {
    return @$tree->find($child_id);
}

// parent | child
// --------------
// 1        2
// 1        3
// 3        5
// 2        6
// 6        8

//                  1
//               2     3
//             6         5
//           8
//
// is 2 ancestor of 8?  yes.
// is 2 ancestor of 5?   no.

// determines if ancestor_id is an ancestor of node_id in tree.
// returns bool
function is_ancestor($tree, $child_id, $ancestor_id): bool {
    $target_id = $child_id;
    while($n = $tree->find($target_id)) {
        if($n->parent_id == $ancestor_id) {
            return true;
        }
        $target_id = $n->parent_id;
    }
    return false;
}

// The do_op function performs the actual work of applying
// a move operation.
//
// This function takes as argument a pair consisting of a 
// Move operation and the current tree and it returns a pair
// consisting of a LogMove operation (which will be added to the log) and
// an updated tree.
function do_op(op_move $op, tree $t): array {

    // When a replica applies a Move op to its tree, it also records
    // a corresponding LogMove op in its log.  The t, p, m, and c
    // fields are taken directly from the Move record, while the oldp
    // field is filled in based on the state of the tree before the move.
    // If c did not exist in the tree, oldp is set to None.  Otherwise
    // oldp records the previous parent and metadata of c.
    $oldp = get_parent($t, $op->child_id);
    $log = new log_op_move($op, $oldp);

    // ensures no cycles are introduced.  If the node c
    // is being moved, and c is an ancestor of the new parent
    // newp, then the tree is returned unmodified, ie the operation
    // is ignored.
    // Similarly, the operation is also ignored if c == newp
    if($op->child_id === $op->parent_id ||
       is_ancestor($t, $op->parent_id, $op->child_id)) {
//        echo "tree unchanged!\n";
        return [$log, $t];
    }

    // Otherwise, the tree is updated by removing c from
    // its existing parent, if any, and adding the new
    // parent-child relationship (newp, m, c) to the tree.
    $t->rm_child($op->child_id);
    $tt = new tree_node($op->parent_id, $op->metadata);
    $t->add_node($op->child_id, $tt);
//    echo "tree changed!\n";
    return [$log, $t];
}

// The do_op function is sufficient for appylying operations if
// all replicas apply operations in the same order.  However in
// an optimistic replication setting, each replica may apply the
// operations in a different order, and we need to ensure that
// the replica state nevertheless converges towards a consistent
// state.  This goal is accomplished by undo_op, redo_op, and
// apply_op functions.
//
// When a replica needs to apply an operation with timestamp t,
// it first undoes the effect of any operations with a timestamp
// greater than t, then performs the new operation,
// and finally re-applies the undone operations.  As a result,
// the state of the tree is as if the operations had all been
// applied in order of increasing timestamp, even though in fact
// they might have been applied in any order.

// The apply_op function takes two arguments: a Move operation
// to apply and the current replica state; and it returns the
// new replica state.

$undo_call_cnt = 0;  // for gathering stats, not part of algo.

// undo_op inverts the effect of a previous move operation
// by restoring the prior parent and metadata that were
// recorded in the LogMove's additional field.
function undo_op(log_op_move $log, tree $t): tree {
    $GLOBALS['undo_call_cnt'] ++;  // for stats, not part of algo    

    if(is_null($log->oldp)) {
        $t->rm_child($log->child_id);
    } else {
        $t->rm_child($log->child_id);

        $oldp = $log->oldp;
        $t->add_node($log->child_id, new tree_node($oldp->parent_id, $oldp->meta));
    }

    return $t;
}

$redo_call_cnt = 0;  // for gathering stats, not part of algo.

// redo_op uses do_op to perform an operation
// again and recomputes the LogMove record (which
// might have changed due to the effect of the new operation)
function redo_op(log_op_move $logop, state $state): state {
    $GLOBALS['redo_call_cnt'] ++;  // for stats, not part of algo    

    $op = op_move::from_log_op_move($logop);
    list($logop2, $tree2) = do_op($op, $state->tree);
    $state->add_log_entry($logop2);
    $state->tree = $tree2;
    return $state;
}

// See general description of apply/undo/redo above.
//
// The apply_op func takes two arguments:
// a Move operation to apply and the current replica
// state; and it returns the new replica state.
// The constrains `t::{linorder} in the type signature
// indicates that timestamps `t are instance if linorder
// type class, and they can therefore be compared with the
// < operator during a linear (or total) order.
function apply_op(op_move $op1, state $state): state {
    if(count($state->log_op_list) == 0) {
        list($op2, $tree2) = do_op($op1, $state->tree);
        return new state([$op2], $tree2);
    } else {
        $ops = $state->log_op_list;
        $logop = array_shift($ops);  // take from beginning of array
        if($op1->timestamp->eq($logop->timestamp)) {
            // This case should never happen in normal operation
            // because it is required that all timestamps are unique.
            // The crdt paper does not even check for this case.
            //
            // We throw an exception to catch it during dev/test.
            throw new Exception("applying op with timestamp equal to previous op.  Every op should have a unique timestamp.");

            // Or production code could just treat it as a non-op.
            return $state;
        } else if($op1->timestamp->lt($logop->timestamp)) {
            $tree2 = undo_op($logop, $state->tree);
            $undone_state = new state($ops, $tree2);
            $applied_state = apply_op($op1, $undone_state);
            $d = redo_op($logop, $applied_state);
            return $d;
        } else {
            list($op2, $tree2) = do_op($op1, $state->tree);
            $state->add_log_entry($op2);
            $state->tree = $tree2;
            return $state;
        }
    }
}

/*****************************************
 * clock_interface
 *****************************************/

// An interface for a clock.  Allows us to choose between
// la_time or global_time.
interface clock_interface {
    function __construct($actor_id);
    function inc();
    function actor_id();
    function merge(clock_interface $other);
    function gt(clock_interface $other);
    function lt(clock_interface $other);
    function eq(clock_interface $other);
}

// implements lamport timestamp + actor tuple.
class la_time implements clock_interface {
    public $actor_id;
    public $counter;

    function __construct($actor_id) {
        $this->actor_id = $actor_id;
        $this->counter = 0;
    }

    // returns a new la_time with same actor but counter incremented by 1.
    function inc() {
        $n = new la_time($this->actor_id);
        $n->counter = $this->counter + 1;
        return $n;
    }

    function actor_id() {
        return $this->actor_id;
    }

    // returns a new la_time with same actor but counter is
    // max(this_counter, other_counter)
    function merge(clock_interface $other) {
        $n = new la_time($this->actor_id);
        $n->counter = max($this->counter, $other->counter);
        return $n;
    }

    // compares this la_time with another.
    // if counters are unequal, returns -1 or 1 accordingly.
    // if counters are equal, returns -1, 0, or 1 based on actor_id.
    //    (this is arbitrary, but deterministic.)
    function compare(la_time $other) {
        if($this->counter == $other->counter) {
            if( $this->actor_id < $other->actor_id) {
                return -1;
            }
            else if( $this->actor_id > $other->actor_id) {
                return 1;
            }
            else {
                return 0;
            }
        }
        else if ($this->counter > $other->counter) {
            return 1;
        }
        else if ($this->counter < $other->counter) {
            return -1;
        }
    }

    // returns true if this la_time is greater than other la_time.
    function gt(clock_interface $other) {
        return $this->compare($other) == 1;
    }

    function lt(clock_interface $other) {
        return $this->compare($other) == -1;
    }

    function eq(clock_interface $other) {
        return $this->compare($other) === 0;
    }
}

// This clock just uses a global (shared state) counter.
// It is useful only for simulations.
class global_time implements clock_interface {
    private static $global_counter = 0;
    private $actor_id;
    public $count;

    function __construct($actor_id) {
        self::$global_counter ++;
        $this->count = self::$global_counter;
        $this->actor_id = $actor_id;
    }

    function inc() {
        return new global_time(null);
    }

    function actor_id() {
        return $this->actor_id;
    }

    function merge(clock_interface $other) {
        $n = new global_time(null);
        $n->count = max($this->count, $other->count);
        return $n;
    }

    function gt(clock_interface $other) {
        return $this->count > $other->count;
    }

    function lt(clock_interface $other) {
        return $this->count < $other->count;
    }

    function eq(clock_interface $other) {
        return $this->count === $other->count;
    }
}


/*****************************************
 * Helper Routines for Testing
 *****************************************/

// returns a new globally unique ID. must not duplicate between
// replicas.
//
// In practice, this would be some type of UUID
function new_id(): int {
    static $ids = 0;
    return $ids++;
}

// print a treenode, recursively
function print_treenode(tree $tree, $node_id, $depth=0) {
    $tn = $tree->find($node_id);
    
    $indent = str_pad("", $depth*2);
    printf("%s- %s\n", $indent, $node_id === null ? '/' : $tn->meta);

    foreach($tree->children($node_id) as $c) {
        print_treenode($tree, $c, $depth+1);
    }
}

// print a tree.  (by first converting to a treenode)
function print_tree(tree $t) {
    // $root = tree_to_treenode($t);
    print_treenode($t, null);
}

// Test helper routine
function print_replica_trees(state $repl1, state $repl2) {
    echo "\n--replica_1 --\n";
    print_tree($repl1->tree);
    echo "\n--replica_2 --\n";
    print_tree($repl2->tree);
    echo "\n";
}

class replica {
    public $id;      // globally unique id.
    public $state;   // state
    public $time;    // must implement clock_interface

    public $replica_list = [];  // list of replica IDs.
    public $latest_time_by_replica = []; 

    static private $clock_type = "la_time";   // la_time | global_time

    function __construct($id = null) {
        if(!$id) {
            $this->id = uniqid("replica_", true);
        }
        $this->state = new state();

        switch(self::$clock_type) {
            case 'la_time': $this->time = new la_time($this->id); break;
            case 'global_time': $this->time = new global_time($this->id); break;
            default: throw new Exception("Unknown clock type");
        }
    }

    // set array of replica IDs.
    function set_replica_list(array $replicas) {
        foreach($replicas as $id) {
            $this->replica_list[$id] = true;
        }
    }

    function apply_ops(array $ops, $debug = false) {
        foreach($ops as $op) {
            if($debug) {
                $state = apply_op($op, $this->state);
                $this->state = $state;
                printf("%s\n", json_encode($this->state, JSON_PRETTY_PRINT));
                echo "--\n";
                printf("%s\n", json_encode($state, JSON_PRETTY_PRINT));
                echo "==========================\n";
            } else 
            $this->state = apply_op($op, $this->state);
            $this->time->merge($op->timestamp);

            $id = $op->timestamp->actor_id();
            if( @$this->replica_list[$id] ) {
                $latest = @$this->latest_time_by_replica[$id];
                if(!$latest || $op->timestamp->gt($latest)) {
                    $this->latest_time_by_replica[$id] = $op->timestamp;
                }
            }
        }
    }

    function causally_stable_threshold(): ?clock_interface {
        // Check that we have a known latest timestamp for each
        // known replica.   If not, there is no causally stable threshold.
        $cnt_known_replicas = count($this->replica_list);
        $cnt_known_times = count($this->latest_time_by_replica);
        if($cnt_known_replicas != $cnt_known_times) {
            return null;
        }

        // The minimum of latest timestamp from each replica
        // is the causally stable threshold.
        $oldest = null;
        foreach( $this->latest_time_by_replica as $id => $timestamp ) {
            if(!$oldest || $timestamp->lt($oldest)) {
                $oldest = $timestamp;
            }
        }
        return $oldest;
    }

    function truncate_log(): bool {
        $t = $this->causally_stable_threshold();
        if($t) {
            return $this->state->truncate_log_before($t);
        }
        return false;
    }

    function tick() {
        $this->time = $this->time->inc();
        return $this->time;
    }
    
}

// Returns operations representing a depth-first tree, 
// with 2 children for each parent.
function mktree_ops(array &$ops, replica $r, $parent_id, $depth=2, $max_depth=12) {
    if($depth > $max_depth) {
        return;
    }
    for($i=0; $i < 2; $i++) {
        $name = sprintf( "%s", $i == 0 ? 'a' : 'b' );
        $ops[] = new op_move($r->tick(), $parent_id, $name, $child_id = new_id());
        mktree_ops($ops, $r, $child_id, $depth+1, $max_depth);
    }
}


/*****************************************
 * Test Routines
 *****************************************/

// Tests case 1 in the paper.  Concurrent moves of the same node.
//
// Initial State:
// root
//  - A
//  - B
//  - C
//
// Replica 1 moves A to be a child of B, while concurrently
// replica 2 moves the same node A to be a child of C.
// a child of B.  This could potentially result in A being
// duplicated under B and C, or A having 2 parents, B and C.
//
// The only valid result is for one operation
// to succeed and the other to be ignored, but both replica's
// must pick the same success case.
//
// See paper for diagram.
function test_concurrent_moves() {
    $r1 = new replica();
    $r2 = new replica();

    // Setup initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id()),
            new op_move($r1->tick(), $root_id, "a", $a_id = new_id()),
            new op_move($r1->tick(), $root_id, "b", $b_id = new_id()),
            new op_move($r1->tick(), $root_id, "c", $c_id = new_id()),
    ];

    $r1->apply_ops($ops);             // applied own ops
    $r2->apply_ops($ops);             // merged ops from r1.

    echo "Initial tree state on both replicas\n";
    print_tree($r1->state->tree);

    // replica_1 moves /root/a to /root/b
    $repl1_ops = [new op_move($r1->tick(), $b_id, "a", $a_id)];

    // replica_2 "simultaneously" moves /root/a to /root/c
    $repl2_ops = [new op_move($r2->tick(), $c_id, "a", $a_id)];

    // replica_1 applies his op, then merges op from replica_2
    $r1->apply_ops($repl1_ops);

    echo "\nreplica_1 tree after move\n";
    print_tree($r1->state->tree);
    $r1->apply_ops($repl2_ops);
    print_tree($r1->state->tree);

    // replica_2 applies his op, then merges op from replica_1
    $r2->apply_ops($repl2_ops);
    echo "\nreplica_2 tree after move\n";
    print_tree($r2->state->tree);
    $r2->apply_ops($repl1_ops);

    // expected result: state is the same on both replicas
    // and final path is /root/c/a because last-writer-wins
    // and replica_2's op has a later timestamp.
    if ($r1->state->is_equal($r2->state)) {
        echo "\nreplica_1 state matches replica_2 state after each merges other's change.  conflict resolved!\n";
        print_replica_trees($r1->state, $r2->state);
    } else {
        echo "\nwarning: replica_1 state does not match replica_2 state after merge\n";
        print_replica_trees($r1->state, $r2->state);
        file_put_contents("/tmp/repl1.json", json_encode($r1, JSON_PRETTY_PRINT));
        file_put_contents("/tmp/repl2.json", json_encode($r2, JSON_PRETTY_PRINT));
    }
    $r1->state->check_log_is_descending();
    return;
}

// Tests case 2 in the paper.  Moving a node to be a descendant of itself.
//
// Initial State:
// root
//  - A
//    - C
//  - B
//
// Initially, nodes A and B are siblings.  Replica 1 moves B
// to be a child of A, while concurrently replica 2 moves A to be
// a child of B.  This could potentially result in a cyle, or
// duplication.  The only valid result is for one operation
// to succeed and the other to be ignored, but both replica's
// must pick the same success case.
//
// See paper for diagram.
function test_concurrent_moves_cycle() {
    $r1 = new replica();
    $r2 = new replica();

    // Setup initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id()),
            new op_move($r1->tick(), $root_id, "a", $a_id = new_id()),
            new op_move($r1->tick(), $root_id, "b", $b_id = new_id()),
            new op_move($r1->tick(), $a_id, "c", $c_id = new_id()),
    ];

    $r1->apply_ops($ops);  // applied own ops
    $r2->apply_ops($ops);  // merged ops from r1.

    echo "Initial tree state on both replicas\n";
    print_tree($r1->state->tree);

    // replica_1 moves /root/b to /root/a
    $repl1_ops = [new op_move($r1->tick(), $a_id, "b", $b_id)];

    // replica_2 "simultaneously" moves /root/a to /root/b
    $repl2_ops = [new op_move($r2->tick(), $b_id, "a", $a_id)];

    // replica_1 applies his op, then merges op from replica_2
    $r1->apply_ops($repl1_ops);

    echo "\nreplica_1 tree after move\n";
    print_tree($r1->state->tree);
    $r1->apply_ops($repl2_ops);

    // replica_2 applies his op, then merges op from replica_1
    $r2->apply_ops($repl2_ops);
    echo "\nreplica_2 tree after move\n";
    print_tree($r2->state->tree);
    $r2->apply_ops($repl1_ops);

    // expected result: state is the same on both replicas
    // and final path is /root/c/a because last-writer-wins
    // and replica_2's op has a later timestamp.
    if ($r1->state->is_equal($r2->state)) {
        echo "\nreplica_1 state matches replica_2 state after each merges other's change.  conflict resolved!\n";
        print_replica_trees($r1->state, $r2->state);
    } else {
        echo "\nwarning: replica_1 state does not match replica_2 state after merge\n";
        print_replica_trees($r1->state, $r2->state);
        file_put_contents("/tmp/repl1.json", json_encode($r1, JSON_PRETTY_PRINT));
        file_put_contents("/tmp/repl2.json", json_encode($r2, JSON_PRETTY_PRINT));
    }
    $r1->state->check_log_is_descending();
    return;
}


// Tests performing two moves concurrently without conflict.
//
// Initial State:
// root
//  - A
//  - B
//
// Initially, nodes A and B are siblings.  Replica 1 moves A
// to C, while concurrently replica 2 moves B to D.
// Both of these operations should succeed because there is no
// conflict, so the final state should be:
//
// root
//  - C
//  - D
function test_concurrent_moves_no_conflict() {
    $r1 = new replica();
    $r2 = new replica();

    // Setup initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id()),
            new op_move($r1->tick(), $root_id, "a", $a_id = new_id()),
            new op_move($r1->tick(), $root_id, "b", $b_id = new_id()),
    ];

    $r1->apply_ops($ops);    // applied own ops
    $r2->apply_ops($ops);    // merged ops from r1.

    echo "Initial tree state on both replicas\n";
    print_tree($r1->state->tree);

    // replica_1 moves /root/a to /root/c
    $repl1_ops = [new op_move($r1->tick(), $root_id, "c", $a_id)];

    // replica_2 "simultaneously" moves /root/b to /root/d
    $repl2_ops = [new op_move($r2->tick(), $root_id, "d", $b_id)];

    // replica_1 applies his op, then merges op from replica_2
    $r1->apply_ops($repl1_ops);

    echo "\nreplica_1 tree after move\n";
    print_tree($r1->state->tree);
    $r1->apply_ops($repl2_ops);

    // replica_2 applies his op, then merges op from replica_1
    $r2->apply_ops($repl2_ops);
    echo "\nreplica_2 tree after move\n";
    print_tree($r2->state->tree);
    $r2->apply_ops($repl1_ops);

    // expected result: state is the same on both replicas
    // and final path is /root/c/a because last-writer-wins
    // and replica_2's op has a later timestamp.
    if ($r1->state->is_equal($r2->state)) {
        echo "\nreplica_1 state matches replica_2 state after each merges other's change.  conflict resolved!\n";
        print_replica_trees($r1->state, $r2->state);
    } else {
        echo "\nwarning: replica_1 state does not match replica_2 state after merge\n";
        print_replica_trees($r1->state, $r2->state);
        file_put_contents("/tmp/repl1.json", json_encode($r1, JSON_PRETTY_PRINT));
        file_put_contents("/tmp/repl2.json", json_encode($r2, JSON_PRETTY_PRINT));
    }
    $r1->state->check_log_is_descending();
    return;
}

// Tests that operations can be applied in any order
// and result in the same final state.
//
// This test generates an initial tree state on replica 1
// then enters a loop (100000 iterations) where each iteration
// shuffles the 6 operations that created the tree to create
// a different order, applies them on replica 2, and tests if 
// replica 2 state matches replica 1 state.
//
// It also calc and prints out some performance stats.
function test_apply_ops_random_order() {

    $r1 = new replica();
    $r2 = new replica();

    // Generate initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id()),
            new op_move($r1->tick(), null, "trash", $trash_id = new_id()),
            new op_move($r1->tick(), $root_id, "home", $home_id = new_id()),
            new op_move($r1->tick(), $home_id, "dilbert", $dilbert_id = new_id()),
            new op_move($r1->tick(), $home_id, "dogbert", $dogbert_id = $dilbert_id),
            new op_move($r1->tick(), $dogbert_id, "cycle_not_allowed", $home_id),
    ];

    $r1->apply_ops($ops);
    $r1->state->check_log_is_descending();

    $start = microtime(true);
    $num_ops = count($ops);

    printf("Applying move operations from replica1 to replica2 in random orders...\n");
    $all_equal = true;

    for($i = 0; $i < 100000; $i ++) {
        $ops2 = $ops;
        shuffle($ops2);

        $r2 = new replica();
        $r2->apply_ops($ops2);

        if($i % 10000 == 0) {
            printf("$i... ");
        }
        $r2->state->check_log_is_descending();

        if (!$r1->state->is_equal($r2->state)) {
            file_put_contents("/tmp/repl1.json", json_encode($r1, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/repl2.json", json_encode($r2, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/ops1.json", json_encode($ops, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/ops2.json", json_encode($ops2, JSON_PRETTY_PRINT));
            printf( "\nreplica_1 %s replica_2\n", $r1->state->is_equal($r2->state) ? "is equal to" : "is not equal to");
            $all_equal = false;
            break;
        }
    }
    $end = microtime(true);
    $elapsed = $end - $start;
    
    if($all_equal) {
        echo "\n\nStates were consistent and log timestamps descending after each apply.  :-)\n";
    } else {
        echo "\n\nFound an inconsistent state.  Check /tmp/ops{1,2}.json and /tmp/repl{1,2}.json.\n";
    }

    $tot_ops = $num_ops*$i;
    printf("\nops_per_apply: %s, applies: %s, total_ops: %s, duration: %.8f, secs_per_op: %.8f\n", $num_ops, $i, $tot_ops, $elapsed, $elapsed / $tot_ops);
}

function test_add_nodes() {

    $r1 = new replica();

    // Generate initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id())];
    mktree_ops($ops, $r1, $root_id);

    $start = microtime(true);

    $r1->apply_ops($ops);
    // print_tree($r1->state->tree);
    
    $end = microtime(true);
    $elapsed = $end - $start;
    
    printf("\ntotal_ops: %s, duration: %.8f, secs_per_op: %.8f\n", count($ops), $elapsed, $elapsed / count($ops));
}

function test_move_node_deep_tree() {

    $r1 = new replica();

    // Generate initial tree state.
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id())];
    mktree_ops($ops, $r1, $root_id, 2, 12);

    $r1->apply_ops($ops);

    $children = $r1->state->tree->children($root_id);
    list($child_id_a, $meta_a) = $children[0];
    list($child_id_b, $meta_b) = $children[1];

    $start = microtime(true);

    // move /a underneath /b.
    $ops2 = [new op_move($r1->tick(), $child_id_b, "moved", $child_id_a)];

    $r1->apply_ops($ops2);
    
    $end = microtime(true);
    $elapsed = $end - $start;

    // print_tree($r1->state->tree);   

    printf("\nbuild_ops (tree size): %s\n", count($ops));
    printf("\ndeep_move_ops: %s, duration: %.8f, secs_per_op: %.8f\n", count($ops2), $elapsed, $elapsed / count($ops2));
}

function test_walk_deep_tree() {

    $r1 = new replica();

    // Generate initial tree state.
    echo "generating ops...\n";
    $ops = [new op_move($r1->tick(), null, "root", $root_id = new_id())];
    mktree_ops($ops, $r1, $root_id, 2, 13);

    echo "applying ops...\n";
    $start_apply = microtime(true);

    $r1->apply_ops($ops);
    
    $end_apply = microtime(true);
    $elapsed_apply = $end_apply - $start_apply;

    echo "walking tree...\n";
    $start = microtime(true);

    $cb = function($node_id) {};
    $r1->state->tree->walk($root_id, $cb);

    $end = microtime(true);
    $elapsed = $end - $start;
    
    printf("\nnodes in tree: %s\n", count($ops));
    printf("\napply duration: %.8f, per node: %.8f\n", $elapsed_apply, $elapsed_apply / count($ops));
    printf("\ntree walk duration: %.8f, per node: %.8f\n", $elapsed, $elapsed / count($ops));
}

// apply same ops to a list of replicas.
// for testing only.
function apply_ops_to_replicas(array $replicas, array $ops) {
    foreach($replicas as $r) {
        $r->apply_ops($ops);
    }
}

function test_truncate_log() {

    $replicas = [];
    $num_replicas = 5;

    // start some replicas.
    for($i = 0; $i < $num_replicas; $i++) {
        $r = new replica();
        $replicas[$r->id] = $r;
    }

    // each replica must know about all replicas.
    $replica_ids = array_keys($replicas);
    foreach($replicas as $id => $r) {
        $r->set_replica_list($replica_ids);
    }

    // Generate initial tree state.
    $ops = [new op_move($replicas[$id]->tick(), null, "root", $root_id = new_id())];

    echo "generating initial move operations...\n";

    // apply some initial ops to all replicas.
    foreach($replicas as $r) {
        mktree_ops($ops, $r, $root_id, 2, rand(3,8));
    }
    printf("applying %s operations to all %s replicas...\n", count($ops), count($replicas));
    apply_ops_to_replicas($replicas, $ops);

    // apply additional ops to all but one replica.
    $subset = $replicas;
    shuffle($subset);
    array_pop($subset);
    $ops2 = [];
    echo "generating initial move operations for replica subset...\n";
    mktree_ops($ops2, $subset[0], $root_id, 2, rand(5,8));
    printf("applying %s operations to %s replica subset...\n", count($ops2), count($subset));
    apply_ops_to_replicas($subset, $ops2);

    $stats = [];
    foreach($replicas as $r) {
        echo "truncating log of replica {$r->id}...\n";
        $id = $r->id;
        $stats[$id]['ops_before_truncate'] = count($r->state->log_op_list);
        $r->truncate_log();
        $stats[$id]['ops_after_truncate'] = count($r->state->log_op_list);
    }

    echo "\n";
    print_r($stats);
}


function test_move_to_trash() {
}


/*****************************************
 * Main.   Let's run some tests.
 *****************************************/

function main() {
    $test = @$GLOBALS['argv'][1];
    switch($test) {
        case 'test_concurrent_moves': test_concurrent_moves(); break;
        case 'test_concurrent_moves_cycle': test_concurrent_moves_cycle(); break;
        case 'test_apply_ops_random_order': test_apply_ops_random_order(); break;
        case 'test_concurrent_moves_no_conflict': test_concurrent_moves_no_conflict(); break;
        case 'test_add_nodes'; test_add_nodes(); break;
        case 'test_move_node_deep_tree': test_move_node_deep_tree(); break;
        case 'test_walk_deep_tree': test_walk_deep_tree(); break;
        case 'test_truncate_log': test_truncate_log(); break;
        default: print_help(); exit;
    }

    global $redo_call_cnt;
    global $undo_call_cnt;
    printf("\nundo called %s times\n", $undo_call_cnt);
    printf("redo called %s times\n", $redo_call_cnt);
}


function print_help() {
    echo <<< END
Usage: tree.php <test>

<test> can be any of:
  test_concurrent_moves
  test_concurrent_moves_cycle
  test_concurrent_moves_no_conflict
  test_apply_ops_random_order
  test_add_nodes
  test_move_node_deep_tree
  test_walk_deep_tree
  test_truncate_log  


END;
}

main();



// Issue: there is presently no fast way to list children of given node.
// Ideas:
//   1. List child nodes in parent node.  (faster for reads)
//   2. Maintain an index in tree of node_id => [child_id]
//   3. include child_ids in node's metadata  (redundant data in log)
//
//   child list/index must be modified when any child is added or removed.
