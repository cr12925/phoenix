<?php

/*
  (c) 2022 Chris Royle
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <https://www.gnu.org/licenses/>.

*/

include_once 'conf.php';
include_once 'ip_lib.php';

dbq("create temporary table msg_temp select msg.msg_id from msg left join msg_read on msg.msg_id = msg_read.msg_id where mr_flags='Deleted' and msg.msg_dest is not null"); // All messages to a particuar recipient that that recipient has deleted

dbq("delete from msg where msg_id in (select msg_id from msg_temp)");
dbq("delete from msg_read where msg_id in (select msg_id from msg_temp)");

?>
