<?php

include_once 'conf.php';
include_once 'ip_lib.php';

dbq("create temporary table msg_temp select msg.msg_id from msg left join msg_read on msg.msg_id = msg_read.msg_id where mr_flags='Deleted' and msg.msg_dest is not null"); // All messages to a particuar recipient that that recipient has deleted

dbq("delete from msg where msg_id in (select msg_id from msg_temp)");
dbq("delete from msg_read where msg_id in (select msg_id from msg_temp)");

?>
