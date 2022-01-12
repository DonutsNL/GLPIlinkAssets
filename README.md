# GLPI linkTicketsToAssets using asset pattern matching

This script will do the following; 

1. Build an array of all available asset hostnames and (if available) IP addresses.
2. Build an array of all ticket (initial content and title);
3. Then searches the title and content for either [hostname] or [hostname.domain.tld] or [IP];
4. If a match is found, the found asset is linked to the ticket and registered in the table amis_tickets_assets_link;
5. Open the script with either Edge of Chrome (firefox doesnt handle eventstreams well) and see the magic;



INSTALLATION;

0. Download the linkAssets folder and put is somewhere on a PHP enabled webserver that has access to the GLPI database. I do not recommend putting this inside the GLPI folders, but that would actually work. 

1. Add the following new table inside the target GLPI database; Use the mysql cli to do so.

create table amis_tickets_assets_link(
id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
ticket_id INT NOT NULL,
computer_id INT NOT NULL,
hit INT NOT NULL,
hittype VARCHAR(50),
keyword VARCHAR(100)
);

2. Open the index.php file and update the database connection data in the constructor to match your environment.
![image](https://user-images.githubusercontent.com/97617761/149178469-bdeaadd2-3a8a-4066-b256-3d341cc86970.png)

3. Open the the index.php file in either Edge, Chrome or Brave (my fav). Dont use firefox. It handles event streams like file downloads and will not work. Then watch the magic happen :)
![image](https://user-images.githubusercontent.com/97617761/149177358-b78e0372-75bc-41c1-9cd6-03d2de5c2bf9.png) 

Enjoy!
