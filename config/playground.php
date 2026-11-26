<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Starting sandboxes
    |--------------------------------------------------------------------------
    |
    | How many playground sandboxes can be started in an hour from one IP
    | address, and by every visitor together (08). The second stops the
    | database filling if many addresses start them at once. .env.example
    | raises the first, since each end-to-end test starts one.
    |
    */

    'sandboxes_per_ip' => (int) env('PLAYGROUND_SANDBOXES_PER_IP', 20),

    'sandboxes_in_all' => (int) env('PLAYGROUND_SANDBOXES_IN_ALL', 500),

];
