<?php

namespace tester;

require_once __DIR__  . '/tests_common.php';

class b_counter extends tests_common {
    
    public function runtests() {
        $this->test1();
    }
    

    protected function test1() {
        
        $replica_ids = $this->replicas(3);
        $replicas = [];
        
        foreach( $replica_ids as $r_id ) {
            $replicas[] = new \b_counter($r_id, $replica_ids);
        }

        $replicas[0]->increment(5);
        $replicas[0]->decrement();
        $replicas[1]->increment();
        
//        $replicas[1]->decrement(3);
        $replicas[2]->increment(2);
        $replicas[0]->increment(3);

        $this->eq($replicas[1]->value(), 1, 'b_counter: replica_1->value() == 1');
        $this->ne($replicas[0]->value(), $replicas[1]->value(), 'b_counter: replica_0->value() != replica_1->value()  [before merge]');        
        $this->ne($replicas[0]->value(), $replicas[2]->value(), 'b_counter: replica_0->value() != replica_2->value()  [before merge]');        
        $this->ne($replicas[1]->value(), $replicas[2]->value(), 'b_counter: replica_1->value() != replica_2->value()  [before merge]');
        
        
        $this->merge_replicas_bcounter($replicas);

        $this->eq($replicas[0]->value(), $replicas[1]->value(), 'b_counter: replicas 0 and 1 equal  [after merge]');
        $this->eq($replicas[1]->value(), $replicas[2]->value(), 'b_counter: replicas 0 and 2 equal  [after merge]');
        $this->eq($replicas[0]->value(), 10, 'b_counter: replica_0->value() == 10  [after merge]');
    }
    
    
    
}
