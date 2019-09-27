# b2upload.js
Direct browser uploads to [backblaze](https://www.backblaze.com/) b2 with [resumable.js](https://github.com/eutychus/resumable.js).  Supports resuming by only uploading missing segments.

## Notes
The backblaze API does not provide a secure way of handling token-based uploads of files smaller than 5MB.  These files will be passed through from your server to backblaze.

Larger files will upload directly to backblaze.

## Requirements

- A backblaze b2 account
- Web server configured with PHP 7 that accepts posts / uploads of at least 5MB.  This is only used for small files.  Large files are uploaded directly to backblaze.

## Getting Started
### Create / update b2 bucket
- Log in to backblaze b2
- create a b2 bucket
- create an app key with permissions   
*deleteFiles, listBuckets, listFiles, readFiles, shareFiles, writeFiles*
- modify CORS rules for bucket.  This can be done using the command line tool or by uncommenting and calling *updateBucketCors* from this library.

### Test and Integrate
- copy api.example.php to api.php
- add [resumable.js](https://github.com/eutychus/resumable.js) to the same folder
- add redis connection (optional, recommended)
- modify the allowFile function to add your own rules to what uploads are allowed
- Add your accountId and applicationKey
- Test uploads using index.php, add to your page as you would with resumable.js
