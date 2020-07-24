<?php

class dot {
    public $actor_id;
    public $counter;

    function __construct($actor_id, $counter) {
        $this->actor_id = $actor_id;
        $this->counter = $counter;
    }

    function inc() {
        return new dot($this->actor_id, $this->counter + 1);
    }
}

class v_clock {
    /// dots is the mapping from actors to their associated counters
    public $dots = [];    // actor_id => u64

    function __construct(array $dots = []) {
        foreach($dots as $d) {
            $this->dots[$d->actor_id] = $d->counter;
        }
    }

    function array_greater(array $a1, array $a2) {
        foreach($a2 as $k2 => $v2) {
            $v1 = @$a1[$k2];
            if(!($v1 >= $v2)) {
                return false;
            }
        }
        return true;
    }

    function partial_cmp(v_clock $other) {
        // This algorithm is pretty naive, I think there's a way to
        // just track if the ordering changes as we iterate over the
        // active dots zipped by actor.
        // ie. it's None if the ordering changes from Less to Greator
        //     or vice-versa.

        if($this->dots == $other->dots) {
            return 0;
        } else if($this->array_greater($this->dots, $other->dots)) {
            return 1;
        } else if($this->array_greater($other->dots, $this->dots)) {
            return -1;
        } else {
            return null;
        }
    }

    function gt(v_clock $other) {
        return $this->partial_cmp($other) == 1;
    }

    function gte(v_clock $other) {
        $result = $this->partial_cmp($other);
        return $result == 1 || $result === 0;
    }

    function lt(v_clock $other) {
        return $this->partial_cmp($other) == -1;
    }

    function lte(v_clock $other) {
        $result = $this->partial_cmp($other);
        return $result == -1 || $result === 0;
    }

    function eq(v_clock $other) {
        return $this->partial_cmp($other) === 0;
    }

    function ne(v_clock $other) {
        return $this->partial_cmp($other) !== 0;
    }

    function to_string() {
        $s = "<";
        $i = 0;
        foreach($this->dots as $actor => $count) {
            if($i++ > 0) {
                $buf .= ", ";
            }
            $buf .= sprintf("%s:%s", actor, count);
        }
        $buf .= ">";
        return $buf;
    }

    function apply(dot $dot) {
        return $this->apply_dot($dot);
    }

    function apply_dot(dot $dot) {
        if($this->get($dot->actor_id) < $dot->counter) {
            $this->dots[$dot->actor_id] = $dot->counter;
        }
        return $this;
    }

    function apply_inc($actor_id) {
        return $this->apply($this->inc($actor_id));
    }

    function merge(v_clock $other) {
        foreach($other->dots as $actor => $counter) {
            $this->apply_dot(new dot($actor, $counter));
        }
    }

    function inc($actor_id) {
        return $this->dot($actor_id)->inc();
    }

    /// Return the associated counter for this actor.
    /// All actors not in the vclock have an implied count of 0
    function get($actor_id) {
        return @$this->dots[$actor_id] ?: 0;
    }    

    function dot($actor_id) {
        $counter = $this->get($actor_id);
        return new dot($actor_id, $counter);
    }
    
    function concurrent(v_clock $other) {
        return $this->partial_cmp($other) === null;
    }

    function is_empty() {
        return count($this->dots) == 0;
    }    
}