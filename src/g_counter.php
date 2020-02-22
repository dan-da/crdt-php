<?php

class g_counter {
    
    private $map = [];    // replica_id : int
    private $my_replica_id;
    
    public function __construct($my_replica_id, $all_replica_ids) {
        $this->my_replica_id = $my_replica_id;
        
        foreach($all_replica_ids as $id) {
            $this->map[$id] = 0;
        }

        // should be redundant. just in case my id not included in all.
        $this->map[$this->my_replica_id] = 0;  
    }
    
    public function increment($step = 1) {
        $this->map[$this->my_replica_id] += $step;
    }
    
    public function value() {
        return array_sum($this->map);
    }

    static public function merge(g_counter &$a, g_counter &$b) {
        foreach( $a->map as $id => $a_count ) {
            $b_count = @$b->map[$id];
            $a->map[$id] = $b->map[$id] = max( $a_count, $b_count );
        }
        
    }
}
