# attribute-validator

Used to find PHP 8 attributes without defined classes.

## Installation

Add notion-commotion/attribute-validator as a requirement to composer.json:

```json
{
    "require": {
       "notion-commotion/attribute-validator"
    }
}
```

```
composer update
```

## Usage

    use NotionCommotionAttributeValidator;
    $path = 'src';              // All files in directory
    //or
    $path = 'src/somefile.php'; // A single file 
    $attributeValidator = AttributeValidator::create($path);
    $attributeValidator->validate();