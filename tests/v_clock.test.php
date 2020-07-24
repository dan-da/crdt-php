<?php

namespace tester;

require_once __DIR__  . '/tests_common.php';

class v_clock extends tests_common {
    
    public function runtests() {
        $this->test_merge();
        $this->test_merge_less_left();
        $this->test_merge_less_right();
        $this->test_merge_same_id();
        $this->test_vclock_ordering();
    }

    function test_merge() {
        $a = new \v_clock([new \dot(1, 1), new \dot(4, 4)]);
        $b = new \v_clock([new \dot(3, 3), new \dot(4, 3)]);

        $a->merge($b);

        $expected = new \v_clock([new \dot(1, 1), new \dot(3, 3), new \dot(4, 4)]);

        $this->assert_eq($a, $expected, "vclock: merge");
    }

    function test_merge_less_left() {
        $a = new \v_clock();
        $b = new \v_clock();
        $a->apply(new \dot(5, 5));

        $b->apply(new \dot(6, 6));
        $b->apply(new \dot(7, 7));

        $a->merge($b);

        // var_dump($a->get(5), $a->get(6), $a->get(7));

        $this->assert_eq($a->get(5), 5, "vclock: merge_less_left(5)");
        $this->assert_eq($a->get(6), 6, "vclock: merge_less_left(6)");
        $this->assert_eq($a->get(7), 7, "vclock: merge_less_left(7)");
    }

    function test_merge_less_right() {
        $a = new \v_clock();
        $b = new \v_clock();

        $a->apply(new \dot(6, 6));
        $a->apply(new \dot(7, 7));

        $b->apply(new \dot(5, 5));

        $a->merge($b);

        // var_dump($a->get(5), $a->get(6), $a->get(7));

        $this->assert_eq($a->get(5), 5, "vclock: merge_less_right(5)");
        $this->assert_eq($a->get(6), 6, "vclock: merge_less_right(6)");
        $this->assert_eq($a->get(7), 7, "vclock: merge_less_right(7)");
    }

    function test_merge_same_id() {
        $a = new \v_clock();
        $b = new \v_clock();

        $a->apply(new \dot(1, 1));
        $a->apply(new \dot(2, 1));

        $b->apply(new \dot(1, 1));
        $b->apply(new \dot(3, 1));

        $a->merge($b);

        // var_dump($a->get(1), $a->get(2), $a->get(3));

        $this->assert_eq($a->get(1), 1, "vclock: merge_same_id(1)");
        $this->assert_eq($a->get(2), 1, "vclock: merge_same_id(2)");
        $this->assert_eq($a->get(3), 1, "vclock: merge_same_id(3)");
    }

    function test_vclock_ordering() {

        $a = new \v_clock();
        $b = new \v_clock();

        $a->apply(new \dot("A", 1));
        $a->apply(new \dot("A", 2));
        $a->apply(new \dot("A", 0));
        $b->apply(new \dot("A", 1));

        // a {A:2}
        // b {A:1}
        // expect: a dominates
        $this->assert_true($a->gt($b), "vclock: ordering. a dominates");
        $this->assert_true($b->lt($a), "vclock: ordering. a dominates");
        $this->assert_true($a->ne($b), "vclock: ordering. a dominates");

        $b->apply(new \dot("A", 3));
        // a {A:2}
        // b {A:3}
        // expect: b dominates
        $this->assert_true($b->gt($a), "vclock: ordering. b dominates");
        $this->assert_true($a->lt($b), "vclock: ordering. b dominates");
        $this->assert_true($a->ne($b), "vclock: ordering. b dominates");

        $a->apply(new \dot("B", 1));
        // a {A:2, B:1}
        // b {A:3}
        // expect: concurrent
        $this->assert_true($a->ne($b), "vclock: ordering. concurrent");
        $this->assert_true(!$a->gt($b), "vclock: ordering. concurrent");
        $this->assert_true(!$b->gt($a), "vclock: ordering. concurrent");

        $a->apply(new \dot("A", 3));
        // a {A:3, B:1}
        // b {A:3}
        // expect: a dominates
        $this->assert_true($a->gt($b), "vclock: ordering. a dominates");
        $this->assert_true($b->lt($a), "vclock: ordering. a dominates");
        $this->assert_true($a->ne($b), "vclock: ordering. a dominates");

        $b->apply(new \dot("B", 2));
        // a {A:3, B:1}
        // b {A:3, B:2}
        // expect: b dominates
        $this->assert_true($b->gt($a), "vclock: ordering. b dominates");
        $this->assert_true($a->lt($b), "vclock: ordering. b dominates");
        $this->assert_true($a->ne($b), "vclock: ordering. b dominates");

        $a->apply(new \dot("B", 2));
        // a {A:3, B:2}
        // b {A:3, B:2}
        // expect: equal
        $this->assert_true(!($b->gt($a)), "vclock: ordering. equal");
        $this->assert_true(!($a->gt($b)), "vclock: ordering. equal");
        $this->assert_true($a->eq($b), "vclock: ordering. equal");
    }

    function assert_eq($a, $b, $desc = null) {
        $comment = $desc ?: sprintf("a: %s, b: %s", json_encode($a), json_encode($b));
        $this->eq($a, $b, $comment);
    }

    function assert_true($a, $desc = null) {
        $desc = $desc ?: json_encode($a);
        $this->eq($a === true, true, $desc);
    }

}
