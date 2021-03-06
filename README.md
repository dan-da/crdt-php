# CRDT-PHP: CRDT experiments

This code was initially written to implement a prototype of the
[bounded counter](<https://hal.inria.fr/hal-01248192/document>) CRDT.

### bounded counter

Some articles about bounded counter:
* [State based CRDTs: bounded counter](https://bartoszsypytkowski.com/state-based-crdts-bounded-counter/)
* [Bounded Counters: maintaining numeric invariants with high availability
](https://pages.lip6.fr/syncfree/attachments/article/59/boundedCounter-white-paper.pdf)

The bounded counter requires pn_counter crdt, which requires g_counter crdt,
so those types are implemented as well.

### tree

Next, a prototype of a crdt tree algo has been added.

References:
* [A highly-available move operation for replicated trees and distributed filesystems](https://martin.kleppmann.com/papers/move-op.pdf)
* [CRDT: The Hard Parts](https://martin.kleppmann.com/2020/07/06/crdt-hard-parts-hydra.html)
* [Youtube Video: CRDT: The Hard Parts](<https://youtu.be/x7drE24geUw>)

## Installation

You will need PHP 7.0+ and [composer](https://getcomposer.org/).

On Ubuntu:

```
apt-get install php composer
```

To install as a standalone repo and run tests:

```
$ git clone https://github.com/dan-da/crdt-php && cd crdt-php
$ composer install
```

To use as a library in your own project:

```
$ mkdir -p <project> && cd <project>
$ composer require dan-da/crdt-php:dev-master
```

## Testing

For b_counter, go into the `tests` directory and run `./tester.php`, like so:

```
$ ./tester.php 
Running tests in b_counter...
Running tests in g_counter...
Running tests in pn_counter...
Running tests in v_clock...
[pass] 1 == 1  |  b_counter: replica_1->value() == 1
[pass] 7 != 1  |  b_counter: replica_0->value() != replica_1->value()  [before merge]
[pass] 7 != 2  |  b_counter: replica_0->value() != replica_2->value()  [before merge]
[pass] 1 != 2  |  b_counter: replica_1->value() != replica_2->value()  [before merge]
[pass] 10 == 10  |  b_counter: replicas 0 and 1 equal  [after merge]
[pass] 10 == 10  |  b_counter: replicas 0 and 2 equal  [after merge]
[pass] 10 == 10  |  b_counter: replica_0->value() == 10  [after merge]
[pass] 10 == 10  |  g_counter: replicas 0 and 1 equal
[pass] 10 == 10  |  g_counter: replicas 0 and 2 equal
[pass] 10 == 10  |  g_counter: replica_0->value() == 10
[pass] 6 == 6  |  pn_counter: replicas 0 and 1 equal
[pass] 6 == 6  |  pn_counter: replicas 0 and 2 equal
[pass] 6 == 6  |  pn_counter: replica_0->value() == 6
[pass] object == object  |  vclock: merge
[pass] 5 == 5  |  vclock: merge_less_left(5)
[pass] 6 == 6  |  vclock: merge_less_left(6)
[pass] 7 == 7  |  vclock: merge_less_left(7)
[pass] 5 == 5  |  vclock: merge_less_right(5)
[pass] 6 == 6  |  vclock: merge_less_right(6)
[pass] 7 == 7  |  vclock: merge_less_right(7)
[pass] 1 == 1  |  vclock: merge_same_id(1)
[pass] 1 == 1  |  vclock: merge_same_id(2)
[pass] 1 == 1  |  vclock: merge_same_id(3)
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. concurrent
[pass] 1 == 1  |  vclock: ordering. concurrent
[pass] 1 == 1  |  vclock: ordering. concurrent
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. a dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. b dominates
[pass] 1 == 1  |  vclock: ordering. equal
[pass] 1 == 1  |  vclock: ordering. equal
[pass] 1 == 1  |  vclock: ordering. equal


41 tests passed.
0 tests failed.
```

For `tree`, go into src and run `php tree.php`, like so:

```
$ php tree.php Usage: tree.php <test>

<test> can be any of:
  test_concurrent_moves
  test_concurrent_moves_cycle
  test_apply_ops_random_order</test>
```

```
$ php tree.php test_concurrent_moves

Initial tree state on both replicas

- /
  - root
    - a
    - b
    - c

replica_1 tree after move

- /
  - root
    - b
      - a
    - c

replica_2 tree after move

- /
  - root
    - b
    - c
      - a

replica_1 state matches replica_2 state after each merges other's change. conflict resolved!

--replica_1 --

- /
  - root
    - b
    - c
      - a

--replica_2 --

- /
  - root
    - b
    - c
      - a
```
