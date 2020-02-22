<?php

namespace tester;

require_once __DIR__  . '/tests_common.php';

class g_counter extends tests_common {
    
    public function runtests() {
        $this->test1();
    }
    

    protected function test1() {
        
        $replica_ids = $this->replicas(3);
        $replicas = [];
        
        foreach( $replica_ids as $r_id ) {
            $replicas[] = new \g_counter($r_id, $replica_ids);
        }

        $replicas[0]->increment(5);
        $replicas[2]->increment(2);
        $replicas[0]->increment(3);

        $this->merge_replicas_gcounter($replicas);

        $this->eq($replicas[0]->value(), $replicas[1]->value(), 'g_counter: replicas 0 and 1 equal');
        $this->eq($replicas[1]->value(), $replicas[2]->value(), 'g_counter: replicas 0 and 2 equal');
        $this->eq($replicas[0]->value(), 10, 'g_counter: replica_0->value() == 10');
    }
}
