# Braintum Search configset

A ready-made Solr configuration for a collection Braintum Search will use.

Use this when you are creating a new collection. If you already have one, you
do not need it: run the connection diagnostic in wp-admin, and Braintum Search will
either offer to add its fields through the Schema API or show you exactly what
to paste into your existing schema.

## What is here

| File | Purpose |
| --- | --- |
| `conf/managed-schema.xml` | Field types and Braintum Search's fields |
| `conf/solrconfig.xml` | Request handlers, commit behaviour, caches |
| `conf/stopwords.txt` | Empty. Yours to fill |
| `conf/synonyms.txt` | Empty. Yours to fill |

## SolrCloud

```bash
solr zk upconfig -n braintum-search -d ./configsets/braintum-search -z localhost:9983
curl "http://localhost:8983/solr/admin/collections?action=CREATE&name=wordpress&numShards=1&collection.configName=braintum-search"
```

## Single node (user-managed)

```bash
cp -r configsets/braintum-search /var/solr/data/wordpress
touch /var/solr/data/wordpress/core.properties
curl "http://localhost:8983/solr/admin/cores?action=CREATE&name=wordpress&instanceDir=wordpress"
```

## Docker, for development

```bash
docker run --rm -p 8983:8983 \
  -v "$PWD/configsets/braintum-search:/opt/solr/server/solr/configsets/braintum-search:ro" \
  solr:10 \
  solr-precreate wordpress /opt/solr/server/solr/configsets/braintum-search
```

## Keeping it current

`conf/managed-schema.xml` is generated from the plugin's schema contract, which
is also what the Schema API plan and the wp-admin export are built from. All
three therefore agree by construction. To regenerate after a contract change:

```bash
wp braintum-search schema export
```

## A note on what Braintum Search will and will not change

Braintum Search only ever adds fields whose names begin with `se_`, and only after
you confirm. It never replaces or deletes a field, including its own, and it
never creates field types — an analyser is a much larger commitment than a
field, and a wrong one is not fixable by adding another field. If a field type
this configset defines is missing from your schema, the diagnostic will tell
you rather than trying to create it.
