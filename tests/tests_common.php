<?php

namespace tester;

require_once __DIR__  . '/../vendor/autoload.php';
\strictmode\initializer::init();

use tester;

abstract class tests_common extends tester\test_base {

    protected function replicas($num = 3) {
        $list = [];
        foreach( range(1, $num) as $i ) {
            $list[] = 'replica_' . $i;
        }
        
        return $list;
    }
    
    protected function merge_replicas_gcounter($replicas) {
        foreach($replicas as &$r1) {
            foreach($replicas as &$r2) {
                \g_counter::merge($r1, $r2);
            }
        }
    }
    
    protected function merge_replicas_pncounter($replicas) {
        foreach($replicas as &$r1) {
            foreach($replicas as &$r2) {
                \pn_counter::merge($r1, $r2);
            }
        }
    }

    protected function merge_replicas_bcounter($replicas) {
        foreach($replicas as &$r1) {
            foreach($replicas as &$r2) {
                \b_counter::merge($r1, $r2);
            }
        }
    }

    
}
