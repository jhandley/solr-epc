Installing Solr and MySQL Data Import Handler on Windows
========================================================

Apache Solr is a open source search engine. It builds an index over a set of documents that supports very fast, flexible querying of the data. 
In addition to indexing documents from files, it can also index data from MySQL by adding the MySQL Data Import Handler (DIH).

Solr runs as a standalone web service implemented in Java. It has a web-based admin interface and an http API that can be used to import data and run queries
from other programs.

We will use Solr 3.7.1 which is the latest version as of today.

Prerequisites
-------------

* MySQL
* Java (JRE)

Steps
-----

1) Download solr-7.3.1.zip from http://lucene.apache.org/solr/downloads.html

2) Unzip in the directory of you choice e.g. c:\solr

3) Download MySQL connector from http://dev.mysql.com/downloads/connector/j/ . Current version is 8.0.11 so download the file mysql-connector-java-8.0.11.zip

4) Unzip mysql-connector-java-8.0.11.zip and copy the file mysql-connector-java-8.0.11.jar to C:\solr\contrib\dataimporthandler\lib (you may need to create the lib folder)

5) Start solr by opening a cmd prompt, going to the directory c:\solr and running `bin\solr.cmd start`.

6) Open the solr admin interface in the browser to make sure that it is running by going to http://localhost:8983/solr/

7) Add a new collection to your solr instance. A collection is a place you can import and index documents. In the cmd prompt run `bin\solr.cmd create -c mycollection`

8) Configure the collection to use the MySQL Data Import Handler. Edit the file C:\solr\server\solr\mycollection\conf\solrconfig.xml and add the following lines in the <lib> section:
	```
	<lib dir="${solr.install.dir:../../../..}/contrib/dataimporthandler/lib" regex=".*\.jar" />
	<lib dir="${solr.install.dir:../../../..}/dist/" regex="solr-dataimporthandler-.*\.jar" />```
	```

	In the same file add the following lines in the request handlers section:
	
	```
	<requestHandler name="/dataimport" class="org.apache.solr.handler.dataimport.DataImportHandler">
		<lst name="defaults">
			<str name="config">data-config.xml</str>
		</lst>
	</requestHandler>
	```
9) Configure the collection to use a regular schema.xml file instead of a managed schema. The managed schema allows the addition/deletion/modification of fields via the web interface
which is cumbersome. The unmanaged schema can be modified by editing the schema.xml file.

In C:\solr\server\solr\mycollection\conf\solrconfig.xml add the following line anywhere in the <config> section of the file:

```
	<schemaFactory class="ClassicIndexSchemaFactory"/>
```

Seems like it can go just about anywhere in the file.

Modify the line:

```
  <updateRequestProcessorChain name="add-unknown-fields-to-the-schema" default="${update.autoCreateFields:true}"
```

to

```
  <updateRequestProcessorChain name="add-unknown-fields-to-the-schema" default="${update.autoCreateFields:false}"
```

Rename the file C:\solr\server\solr\mycollection\conf\managed-schema to schema.xml

10) Create a new file C:\solr\server\solr\mycollection\conf\data-config.xml that tells Solr how to import from your database:

```
<dataConfig>
<dataSource type="JdbcDataSource" 
            driver="com.mysql.cj.jdbc.Driver"
            url="jdbc:mysql://localhost/mydatabasename?serverTimezone=UTC&amp;tinyInt1isBit=false" 
            user="username" 
            password="password"/>
<document>
  <entity name="person"  
    pk="id"
    query="SELECT id, first_name, last_name, sex, age FROM people">
     <field column="id" name="id"/>
     <field column="first_name" name="firstName"/>
     <field column="last_name" name="lastName"/>
     <field column="sex" name="sex"/>  
     <field column="age" name="age"/>       
  </entity>
</document>
</dataConfig>
```

For each column in your query add a corresponding <field> with the column attribute set to the name of the column in the query and the name attribute set to the name of the field to use in Solr.

The addition of serverTimezone=UTC in the database URL may not be necessary as the jdbc driver should be able to figure out the timezone itself but without it on my system it failed with an error that default timezone was not valid.

The addition of tinyInt1isBit=false to the database URL prevents MySQL columns of type tinyint from being converted to Java booleans which can be problematic if you have tinyint columns that are actually numeric.
See https://dev.mysql.com/doc/connectors/en/connector-j-reference-type-conversions.html

13) Add fields to the schema by editing the schema.xml file. Each field specified in data-config.xml requires a corresponding field in the schema.xml:

```
	<field name="firstName" type="text_general" indexed="true" stored="true" />
	<field name="lastName" type="text_general" indexed="true" stored="true" />
	<field name="sex" type="pint" indexed="true" stored="true" />
	<field name="age" type="pint" indexed="true" stored="true" />
```

Note that the id field is already defined in schema.xml.

For fields that are only needed for querying but do not need to be returned in the search results you can set stored="false". 
For fields that do not need to be queried but should be in the search results you can set indexed="false".

You can also add custom field types in this file. For example to use Beider Morse phonetic matching for names add the following:

```
	<fieldType name="phoneticBeiderMorse" class="solr.TextField" positionIncrementGap="100">
		<analyzer type="index">
			<tokenizer class="solr.StandardTokenizerFactory" />
			<filter class="solr.BeiderMorseFilterFactory" nameType="GENERIC" ruleType="APPROX" concat="false" languageSet="auto" />
		</analyzer>
		<analyzer type="query">
			<tokenizer class="solr.StandardTokenizerFactory" />
			<filter class="solr.BeiderMorseFilterFactory" nameType="GENERIC" ruleType="APPROX" concat="false" languageSet="auto" />
		</analyzer>
	</fieldType>
```

Now you can use the new field type in the field definitions:

```
	<field name="firstName" type="phoneticBeiderMorse" indexed="true" stored="true" />
	<field name="lastName" type="phoneticBeiderMorse" indexed="true" stored="true" />
```

14) Restart solr to get the configuration changes by running `bin\solr.cmd restart -p 8983`

15) Run the import to transfer data from MySQL to Solr. This can be done using the Dataimport tab of the Solr admin interface or by going to http://localhost:8983/solr/mycollection/dataimport?command=full-import
Note that the import is asynchronous so the only way to know whether or not it has completed successfully is to click the Refresh status button on the data import tab or go to http://localhost:8983/solr/mycollection/dataimport

If the import fails, check the log file in C:\solr\server\logs\solr.log for errors. 

If your database is very large, it may take some time for the import to complete. If the import appears to be hung try hitting the "Ping" tab on the admin interface and then checking the log file.

16) To verify that all your data has been imported run the default query from the query tab of the admin interface. This will retrieve all objects. 

You can refine the query by changing *:* under "q" to <fieldname>:<value>, for example `firstName:Jerry`.

For numeric fields you can use syntax like `age:[15 TO 45]` to find values in a range.

You can query multiple fields at a time by separating them with spaces e.g. `firstName:Jerry sex:1 age:[15 TO 45]`.

You can add boost factors to the terms to one portion of the query more than others by adding ^factor to the end. For example: `firstName:Jerry^1 sex:1^2 age:[15 TO 45]^4`

This gives each result a score and orders the results from the highest score (most relevant) to the lowest. To see the scores enter "*,score" in the "fl" box in the query tab.
To see how the scores are calculated check the "debugQuery" box.

To query outside the browser add q and fl values as query parameters to the url http://localhost:8983/solr/mycollection/query, for example:

http://localhost:8983/solr/mycollection/query?firstName:Jerry%20sex:1%20age:[15%20TO%2045]?fl=*,score

Note that spaces must be url encoded.

References
----------

* https://gist.github.com/rnjailamba/8c872768b136a88a10b1
* https://gist.github.com/rnjailamba/dc5068fbd883d963f7ec
* https://wiki.apache.org/solr/DataImportHandler
* http://www.solrtutorial.com/solr-query-syntax.html
