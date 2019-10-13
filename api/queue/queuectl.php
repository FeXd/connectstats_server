<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2019 Brice Rosenzweig.
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *  
 */

error_reporting(E_ALL);

include_once( '../queue.php');

$queue = new Queue();
$queue->verbose = true;
#$queue->sql->verbose = true;

$queue->ensure_commandline($argv??NULL,1);

if( $argv[1] == 'kill' ){
    $queue->kill_queues();
}else if( $argv[1] == 'start' ){
    $queue->start_queues();
}else if( $argv[1] == 'list' ){
    $queue->list_queues();
}else if( $argv[1] == 'ps' ){
    $queue->find_running_queues();
}else if( $argv[1] == 'add' && count( $argv ) > 2){
    $queue->add_task( $argv[2], getcwd() );
}else if( $argv[1] == 'run' && count( $argv ) > 2 ){
    $queue->run($argv[2]);
}

?>
