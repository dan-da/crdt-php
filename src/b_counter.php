<?php

require_once(__DIR__ . '/pn_counter.php');

// bounded counter
class b_counter {

    private $replica_id;
    
    private $pn;                 // pn_counter, must never be negative, that's what quota is far.
    private $transfers = [];     // map['sender|receiver'] --> int (all quota transferred so far between sender/receiver)
    
    public function __construct($replica_id, $all_replica_ids) {
        $this->replica_id = $replica_id;
        $this->pn = new pn_counter($replica_id, $all_replica_ids);
        
        // initialize all elements of a matrix of all sender/receivers to zero.
        // note: making this a complete matrix (non-sparse) makes our merge function
        // simpler because both merged nodes have all/same keys.  also transfer()
        // func does not need to check if key exists.
        foreach($all_replica_ids as $id_a) {
            foreach($all_replica_ids as $id_b ) {
                $key = $this->key($id_a, $id_b);
                $this->transfers[$key] = 0;
            }
        }
    }
    
    private function quota() {
        
        // start with pn value.
        $value = $this->pn->value();

        // then find transfers where we are either sender or receiver.
        foreach($this->transfers as $k => $v) {
            
            list($sender_id, $receiver_id) = explode('|', $k);
            
            if($sender_id == $this->replica_id) {
                $value -= $v;
            }
            if($receiver_id == $this->replica_id) {
                $value += $v;
            }
        }
        return $value;
    }
    
    public function increment($step = 1) {
        // printf( "%s -- incrementing %s, quota is: %s\n", $this->replica_id, $step, $this->quota() );
        $this->pn->increment($step);
    }
    
    public function decrement($step = 1) {
        // printf( "%s -- decrementing %s, quota is: %s\n", $this->replica_id, $step, $this->quota() );
        $q = $this->quota();
        if( $q < $step ) {
            throw new quota_exception(sprintf("Available quota (%s) is less than decrement amount (%s)", $q, $step));
        }
        $this->pn->decrement($step);
    }
    
    private function key($sender_id, $receiver_id) {
        return sprintf( '%s|%s', $sender_id, $receiver_id);
    }
    
    public function transfer($receiver_id, $amount) {
        $q = $this->quota();
        if( $q < $amount ) {
            throw new quota_exception(sprintf("Available quota (%s) is less than transfer amount (%s)", $q, $amount));
        }
        $key = $this->key($sender_id, $receiver_id);
        $this->transfers[$key] += $amount;
    }
    
    public function value() {
        return $this->pn->value();
    }
    
    static public function merge(b_counter &$a, b_counter &$b) {
        
        pn_counter::merge($a->pn, $b->pn);
        
        foreach($a->transfers as $k => $a_val) {
            $b_val = @$b->map[$id];
            $a->transfers[$k] = $b->transfers[$k] = max( $a_val, $b_val );
        }
    }
}

class quota_exception extends Exception {}