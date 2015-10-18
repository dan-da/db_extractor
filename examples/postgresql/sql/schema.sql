create table "user" (
  "user_id" serial not null primary key,
  "username" varchar(50) not null unique,
  "email" varchar(100) not null,
  "password_hash" varchar(32) not null,
  "fname" varchar(30) null,
  "lname" varchar(30) null
);

create table "group" (
  "group_id" serial not null primary key,
  "groupname" varchar(50) not null
);

create table "user_group" (
  "user_id" integer not null references "user"(user_id) DEFERRABLE INITIALLY DEFERRED,
  "group_id" integer not null references "group"(group_id) DEFERRABLE INITIALLY DEFERRED
);

create view user_group_view as
select
 u.user_id,
 u.username,
 u.email,
 u.password_hash,
 u.fname,
 u.lname,
 g.group_id,
 g.groupname
from
 "user" u
  inner join "user_group" ug using(user_id)
  inner join "group" g using(group_id);
  
insert into "user"(user_id,username,email,password_hash,fname,lname) values(1, 'jim', 'jim@gmail.com', 'asdfasdfasdfasdfdsaf', 'Jim', 'White');
insert into "user"(user_id,username,email,password_hash,fname,lname) values(2, 'sally', 'sally@gmail.com', 'asdfasdfasdfasdfdsaf', 'Sally', 'Maplethorpe');

insert into "group"(group_id, groupname) values(1, 'Administrator');
insert into "group"(group_id, groupname) values(2, 'HelpDesk');
insert into "group"(group_id, groupname) values(3, 'Customer');

insert into user_group(user_id, group_id) values(1,1);
insert into user_group(user_id, group_id) values(1,2);
insert into user_group(user_id, group_id) values(2,3);
