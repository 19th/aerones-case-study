# Aerones case study

My solution includes two variations - PHP + Guzzle async get, NodeJS + Axios and promises.  
As discussed during the interview, it would be interesting to compare solutions.  

Apps fit set requirements:
- ✅ Concurrency
- ✅ File download and resume
- ✅ Retries with exponential backoff
- ✅ Temp and completed directories
- ✅ Logging  

By default, apps have:
* 10 retries
* 5 second timeouts
* exponential backoff of 10 seconds max
* temporary files saved in `temp` folder and `completed` folders
  
## PHP + Guzzle

**Running**  
Just run `./run_php_version.sh` or:

1. Enter `php_version` folder any convenient way you prefer
2. Install dependencies `composer install` 
3. Launch script using `php file-download-guzzle.php -vvv` command.

**Why Guzzle?**  
Guzzle has an option of async non-blocking file download based on Guzzle promises, which makes solution clean and documented. However, it turns out there's not much details on specifics and extension of things like retries and resuming.

## NodeJS + Axios and promises

**Running**  
Just run `./run_php_version.sh` or:

1. Enter `nodejs_version` folder any convenient way you prefer
2. Install dependencies `npm i`
3. Launch script using `node file-download.js ` command.

**Why NodeJS?**   
NodeJS natively has async nature, which makes implementation straightforward. 