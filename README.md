# bitstorm
A very minimal Bittorrent Tracker from 2008. Uses plain text databases (including locking, so no race conditions should happen) and is hence very simple to use.

On Linux, it should be enough to just copy the announce file into your web root and you should be good to go as long as /dev/shm is writable. On other systems, you need to change the path of the flat file database.

There is also an SQL version, check my other repos.
