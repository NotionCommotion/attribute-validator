# attribute-validator

Used to find PHP 8 attributes without defined classes.  While annotations will result in error if the class does not exist, not true for attributes.

## Installation

composer require notion-commotion/attribute-validator

## Usage

    use NotionCommotionAttributeValidator;
    $path = 'src';              // All files in directory
    //or
    $path = 'src/somefile.php'; // A single file 
    $attributeValidator = AttributeValidator::create($path);
    $attributeValidator->validate();