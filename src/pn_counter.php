<?php

require_once(__DIR__ . '/g_counter.php');

class pn_counter {

    private $replica_id;
    
    private $p;  // g_counter1
    private $n;  // g_counter2
    
    public function __construct($replica_id, $all_replica_ids) {
        $this->replica_id = $replica_id;
        $this->p = new g_counter($replica_id, $all_replica_ids);
        $this->n = new g_counter($replica_id, $all_replica_ids);
    }
    
    public function increment($step = 1) {
        $this->p->increment($step);
    }
    
    public function decrement($step = 1) {
        $this->n->increment($step);
    }
    
    public function value() {
        return $this->p->value() - $this->n->value();
    }
    
    static public function merge(pn_counter &$a, pn_counter &$b) {
        
        g_counter::merge($a->p, $b->p);
        g_counter::merge($a->n, $b->n);
        
    }

    
}