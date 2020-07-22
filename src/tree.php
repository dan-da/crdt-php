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
    public $timestamp;   // lamport 
    public $parent_id;   // globally unique, eg uuid
    public $metadata;
    public $child_id;    // globally unique, eg uuid

    function __construct($t, $p, $m, $c) {
        $this->timestamp = $t;
        $this->parent_id = $p;
        $this->metadata = $m;
        $this->child_id = $c;
    }

    static function from_log_op_move(log_op_move $log) {
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
    public $timestamp;   // lamport 
    public $parent_id;   // globally unique, eg uuid
    public $metadata;
    public $child_id;    // globally unique, eg uuid
    public $oldp;        // None/null or [node_id,meta]

    function __construct(op_move $op, $oldp = null) {
        $this->timestamp = $op->timestamp;
        $this->parent_id = $op->parent_id;
        $this->metadata = $op->metadata;
        $this->child_id = $op->child_id;
        $this->oldp = $oldp;
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
    public $log_op_list = [];
    public $tree;

    function __construct(array $ops = [], tree $tree = null) {
        $this->log_op_list = $ops;
        $this->tree = $tree == null ? new tree() : $tree;
    }

    function add_log_entry(log_op_move $entry) {
        // add at beginning of array
        array_unshift($this->log_op_list, $entry);
    }

    function is_equal(state $other) {
        return $this->log_op_list == $other->log_op_list &&
               $this->tree->is_equal($other->tree);
    }
}

// Represents a parent, meta, child triple
// that is stored in an unordered set in a tree 
class tree_triple {
    public $parent_id;
    public $meta;
    public $child_id;

    function __construct($parent_id, $meta, $child_id) {
        $this->parent_id = $parent_id;
        $this->meta = $meta;
        $this->child_id = $child_id;
    }
}

// Represents a tree as a set (unordered list)
// of (parent, meta child) triples.
//
// Presented in paper as:
//   ('n x 'm x 'n)
class tree {
    public $triples = [];

    // helper for removing a triple based on child_id
    function rm_child($child_id) {
        foreach($this->triples as $idx => $tr) {
            if($tr->child_id == $child_id) {
                unset($this->triples[$idx]);
            }
        }
    }

    // test for equality between two trees.
    function is_equal(tree $other) {
        // We must treat the triples array as an unordered set
        // (where the two sets are equal even if values are present
        // in a different order).
        // Therefore, we cannot simply check if array_values()
        // for each set is equal.
        foreach($this->triples as $t) {
            if(!in_array($t, $other->triples)) {
                return false;
            }
        }
        foreach($other->triples as $t) {
            if(!in_array($t, $this->triples)) {
                return false;
            }
        }
        return true;
    }
}

// ------ Operations / Functions ----------

// finds parent of a given child node in a tree.
// returns [parent_id, meta]
function get_parent(tree $tree, $node_id) {

    foreach($tree->triples as $tr) {
        if($tr->child_id == $node_id) {
            return [$tr->parent_id, $tr->meta];
        }
    }
    return null;
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
function is_ancestor($tree, $node_id, $ancestor_id) {
    $ancestors = [];

    foreach( array_reverse($tree->triples) as $triple ) {
        if( $triple->child_id == $node_id ) {
            $ancestors[] = $triple->parent_id;
        }
        else if(in_array($triple->child_id, $ancestors)) {
            $ancestors[] = $triple->parent_id;
        }
        else {
            continue;
        }
        if ($ancestor_id == $triple->parent_id) {
            return true;
        }
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
function do_op(op_move $op, tree $t) {

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
    $tt = new tree_triple($op->parent_id, $op->metadata, $op->child_id);
    $t->triples[] = $tt;
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
function undo_op(log_op_move $log, tree $t) {
    global $undo_call_cnt;
    $undo_call_cnt ++;

    if(is_null($log->oldp)) {
        $t->rm_child($log->child_id);
    } else {
        $t->rm_child($log->child_id);

        list($oldp, $oldm) = $log->oldp;
        $t->triples[] = new tree_triple($oldp, $oldm, $log->child_id);
    }

    return $t;
}

$redo_call_cnt = 0;  // for gathering stats, not part of algo.

// redo_op uses do_op to perform an operation
// again and recomputes the LogMove record (which
// might have changed due to the effect of the new operation)
function redo_op(log_op_move $log, state $state) {
    global $redo_call_cnt;
    $redo_call_cnt ++;

    $op = op_move::from_log_op_move($log);
    list($log2, $tree2) = do_op($op, $state->tree);
    $state->add_log_entry($log2);
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
function apply_op(op_move $op1, state $state) {
    if(count($state->log_op_list) == 0) {
        list($op2, $tree2) = do_op($op1, $state->tree);
        return new state([$op2], $tree2);
    } else {
        $ops = $state->log_op_list;
        $logop = array_shift($ops);  // take from beginning of array
        if($logop->timestamp > $op1->timestamp) {
            $tree2 = undo_op($logop, $state->tree);
            $undone_state = new state($ops, $tree2);
            $applied_state = apply_op($op1, $undone_state);
            return redo_op($logop, $applied_state);
        } else {
            list($op2, $tree2) = do_op($op1, $state->tree);
            $state->add_log_entry($op2);
            $state->tree = $tree2;
            return $state;
        }
    }
}

/*****************************************
 * Helper Routines for Testing
 *****************************************/

// Applies list of operations to a new (or existing) state
// and returns new state.
function apply_ops(array $operations, state $prev_state = null) {
    $state = $prev_state ?: new state();
    foreach($operations as $op) {
        $state = apply_op($op, $state);
        // var_dump($op, $state);
    }
    return $state;
}

// returns a new globally unique ID. must not duplicate between
// replicas.
//
// In practice, this would be some type of UUID
function new_id() {
    static $ids = 0;
    return $ids++;
}

// returns present timestamp.
// fixme: should be replaced by lamport timestamp (or dotted vector clock?)
//
// using a global clock/counter like this is cheating.
function timestamp() {
    static $clock = 0;
    return $clock++;
}

// This is a helper data structure for representing the
// tree data in a manner that facilitates traversing
// the tree.
class treenode {
    public $id;
    public $meta;
    public $children = [];
    function __construct($id, $meta) {
        $this->id = $id;
        $this->meta = $meta;
    }
}

// converts tree (unordered set of triples)
// to treenode (recursive)
function tree_to_treenode(tree $t) {
    $root = new treenode(null, "/");
    $all = [null => $root];

    foreach($t->triples as $tt) {
        $n = new treenode($tt->child_id, $tt->meta);
        $all[$n->id] = $n;
    }

    foreach($t->triples as $tt) {
        $parent = &$all[$tt->parent_id];
        $n = &$all[$tt->child_id];
        $parent->children[] = $n;
    }
    return $root;
}

// print a treenode, recursively
function print_treenode(treenode $tn, $depth=0) {
    $indent = str_pad("", $depth*2);
    printf("%s- %s\n", $indent, $tn->meta);

    foreach($tn->children as $c) {
        print_treenode($c, $depth+1);
    }
}

// print a tree.  (by first converting to a treenode)
function print_tree(tree $t) {
    $root = tree_to_treenode($t);
    print_treenode($root);
}

/*****************************************
 * Test Routines
 *****************************************/

// Test helper routine
function print_replica_trees(state $repl1, state $repl2) {
    echo "\n--replica_1 --\n";
    print_tree($repl1->tree);
    echo "\n--replica_2 --\n";
    print_tree($repl2->tree);
    echo "\n";
}

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

    // Setup initial tree state.
    $ops = [new op_move(timestamp(), null, "root", $root_id = new_id()),
            new op_move(timestamp(), $root_id, "a", $a_id = new_id()),
            new op_move(timestamp(), $root_id, "b", $b_id = new_id()),
            new op_move(timestamp(), $root_id, "c", $c_id = new_id()),
    ];
    $repl1_state = apply_ops($ops);
    $repl2_state = apply_ops($ops);

    echo "Initial tree state on both replicas\n";
    print_tree($repl1_state->tree);

    // replica_1 moves /root/a to /root/b
    $repl1_ops = [new op_move(timestamp(), $b_id, "a", $a_id)];

    // replica_2 "simultaneously" moves /root/a to /root/c
    $repl2_ops = [new op_move(timestamp(), $c_id, "a", $a_id)];

    // replica_1 applies his op, then merges op from replica_2
    $repl1_state = apply_ops($repl1_ops, $repl1_state);
    echo "\nreplica_1 tree after move\n";
    print_tree($repl1_state->tree);
    $repl1_state = apply_ops($repl2_ops, $repl1_state);

    // replica_2 applies his op, then merges op from replica_1
    $repl2_state = apply_ops($repl2_ops, $repl2_state);
    echo "\nreplica_2 tree after move\n";
    print_tree($repl2_state->tree);
    $repl2_state = apply_ops($repl1_ops, $repl2_state);

    // expected result: state is the same on both replicas
    // and final path is /root/c/a because last-writer-wins
    // and replica_2's op has a later timestamp.
    if ($repl1_state->is_equal($repl2_state)) {
        echo "\nreplica_1 state matches replica_2 state after each merges other's change.  conflict resolved!\n";
        print_replica_trees($repl1_state, $repl2_state);
    } else {
        echo "\nwarning: replica_1 state does not match replica_2 state after merge\n";
        print_replica_trees($repl1_state, $repl2_state);
        file_put_contents("/tmp/repl1.json", json_encode($repl1_state, JSON_PRETTY_PRINT));
        file_put_contents("/tmp/repl2.json", json_encode($repl2_state, JSON_PRETTY_PRINT));
    }
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

    $ops = [new op_move(timestamp(), null, "root", $root_id = new_id()),
            new op_move(timestamp(), $root_id, "a", $a_id = new_id()),
            new op_move(timestamp(), $root_id, "b", $b_id = new_id()),
            new op_move(timestamp(), $a_id, "c", $c_id = new_id()),
    ];
    $repl1_state = apply_ops($ops);
    $repl2_state = apply_ops($ops);

    echo "Initial tree state on both replicas\n";
    print_tree($repl1_state->tree);

    // replica_1 moves /root/b to /root/a
    $repl1_ops = [new op_move(timestamp(), $a_id, "b", $b_id)];

    // replica_2 "simultaneously" moves /root/a to /root/b
    $repl2_ops = [new op_move(timestamp(), $b_id, "a", $a_id)];

    // replica_1 applies his op, then merges op from replica_2
    $repl1_state = apply_ops($repl1_ops, $repl1_state);
    echo "\nreplica_1 tree after move\n";
    print_tree($repl1_state->tree);
    $repl1_state = apply_ops($repl2_ops, $repl1_state);

    // replica_2 applies his op, then merges op from replica_1
    $repl2_state = apply_ops($repl2_ops, $repl2_state);
    echo "\nreplica_2 tree after move\n";
    print_tree($repl2_state->tree);
    $repl2_state = apply_ops($repl1_ops, $repl2_state);

    // expected result: state is the same on both replicas
    // and final path is /root/c/a because last-writer-wins
    // and replica_2's op has a later timestamp.
    if ($repl1_state->is_equal($repl2_state)) {
        echo "\nreplica_1 state matches replica_2 state after each merges other's change.  conflict resolved!\n";
        print_replica_trees($repl1_state, $repl2_state);
    } else {
        echo "\nwarning: replica_1 state does not match replica_2 state after merge\n  Check files in /tmp/repl{1,2}.json\n";
        print_replica_trees($repl1_state, $repl2_state);
        file_put_contents("/tmp/repl1.json", json_encode($repl1_state, JSON_PRETTY_PRINT));
        file_put_contents("/tmp/repl2.json", json_encode($repl2_state, JSON_PRETTY_PRINT));
    }
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

    // Generate initial tree state.
    $ops = [new op_move(timestamp(), null, "root", $root_id = new_id()),
            new op_move(timestamp(), null, "trash", $trash_id = new_id()),
            new op_move(timestamp(), $root_id, "home", $home_id = new_id()),
            new op_move(timestamp(), $home_id, "dilbert", $dilbert_id = new_id()),
            new op_move(timestamp(), $home_id, "dogbert", $dogbert_id = $dilbert_id),
            new op_move(timestamp(), $dogbert_id, "cycle_not_allowed", $home_id),
    ];

    $repl1_state = apply_ops($ops);

    $start = microtime(true);
    $num_ops = count($ops);

    printf("Performing move operations...\n");
    $all_equal = true;

    for($i = 0; $i < 100000; $i ++) {
        $ops2 = $ops;
        shuffle($ops2);
        $repl2_state = apply_ops($ops2);

        if($i % 10000 == 0) {
            printf("$i... ");
        }

        if (!$repl1_state->is_equal($repl2_state)) {
            file_put_contents("/tmp/repl1.json", json_encode($repl1_state, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/repl2.json", json_encode($repl2_state, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/ops1.json", json_encode($ops, JSON_PRETTY_PRINT));
            file_put_contents("/tmp/ops2.json", json_encode($ops2, JSON_PRETTY_PRINT));
            printf( "\nreplica_1 %s replica_2\n", $repl1_state->is_equal($repl2_state) ? "is equal to" : "is not equal to");
            $all_equal = false;
            break;
        }
    }
    $end = microtime(true);
    $elapsed = $end - $start;
    
    if($all_equal) {
        echo "\n\nAll states were consistent after each apply.  :-)\n";
    } else {
        echo "\n\nFound an inconsistent state.  Check /tmp/ops{1,2}.json and /tmp/repl{1,2}.json.\n";
    }

    $tot_ops = $num_ops*$i;
    printf("\nops_per_apply: %s, applies: %s, total_ops: %s, duration: %.8f, secs_per_op: %.8f\n", $num_ops, $i, $tot_ops, $elapsed, $elapsed / $tot_ops);

    global $redo_call_cnt;
    global $undo_call_cnt;
    printf("undo called %s times\n", $undo_call_cnt);
    printf("redo called %s times\n", $redo_call_cnt);
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
        default: print_help(); break;
    }
}

function print_help() {
    echo <<< END
Usage: tree.php <test>

<test> can be any of:
  test_concurrent_moves
  test_concurrent_moves_cycle
  test_apply_ops_random_order

END;
}

main();