# Magento1_EMS_Post
## Installation instructions
Add to "repository" section of composer.json:
```
{
  "type": "package",
  "package": {
    "name": "smartceo/magento1-ems-post",
    "version": "1.0.0",
    "source": {
     "type": "git",
     "url": "https://github.com/e-v-medvedev/Magento1_EMS_Post",
     "reference": "master"
    }
  }
}
```
Add to `require` section of composer.json:
```
"smartceo/magento1-ems-post": "1.0.0"
```

Add to `script -> post-package-install` section of composer.json:
```
"php -r \" system ('cp -R ./vendor/smartceo/magento1-ems-post/app/* ./app && rm -R ./vendor/smartceo/magento1-ems-post '); \""
```
Run
```
$ php composer.phar install
```    
