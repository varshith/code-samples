This is a custom shorthand parser service that allows using shorthand queries like for JSON API 

q=((category=in=(1,2,3);weight=gt=8),special_filter==1);mandatory_filter==1

This will be expanded to 3 condition groups where, 
- the innermost one will a have an 'and' condition between field_category and feld_weight
- the second is bewteen the above group and field_special_filter with an 'or' operator
- the outermost is between the above group and field_mandatory_filter with an 'and' oprator

The ( and ) can be used to nest multiple condition groups easily.

This was used along with query events to preprocess the query string.

Refer https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module/filtering
