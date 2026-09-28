# Lamp Stack Project (Group 9)
## Quick Start
### MacOS:
Prerequisites: 
- brew (install at https://brew.sh/)
- git (```brew install git```)
- (Optional) GitHub personal access token (raise API limits)
- Text editor

1. ```brew install httpd php mysql```
2. ```brew services start httpd php mysql```
3. ```cd /opt/homebrew/var/www```
4. ```git clone https://github.com/morningsoftware/lamp-stack-project.git .```
5. ```mysql -u root -p < resetdb.sql``` 
6. Configure .env.
7. Start the GitHub refresh cron job (defaults to 20 profiles every 6 hours):
   ```./api/cron/refresh_github.sh install 20```
8. Done!

### Linux (Ubuntu):
Prerequisites: 
- git (```sudo apt install git```)
- (Optional) GitHub personal access token (raise API limits)
- Text editor

1. ```sudo apt update && sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-curl```
2. ```sudo systemctl enable --now apache2 mysql```
3. ```cd /var/www/html```
4. ```sudo git clone https://github.com/morningsoftware/lamp-stack-project.git .```
5. ```sudo mysql -u root -p < resetdb.sql```
6. Configure .env.
7. Start the GitHub refresh cron job (defaults to 20 profiles every 6 hours):
   ```./api/cron/refresh_github.sh install 20```
8. Done!
