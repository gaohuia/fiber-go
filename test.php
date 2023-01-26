<?php
/**
 * User: Tom<www@zeroplace.cn>
 * Date: 2023/1/26
 * Time: 17:59
 */


require_once __DIR__ . "/src/lib/api.php";

$routine = function() {
    $rid = get_routine_id();
    for ($i = 0; $i < 100; $i++) {
        async_sleep(mt_rand(100, 800) / 1000);
        echo date('Y-m-d H:i:s') . " Routine $rid $i\n";
    }
};

go($routine);
go($routine);
go($routine);
go($routine);

go(function() {
    $content = async_file_get_contents("/Users/tom/images/sample1.heic");
    echo md5($content);
});

Loop::launch();
echo "loop ended";
