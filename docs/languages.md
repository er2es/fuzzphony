# Languages

How Fuzzphony handles stemming, stop words and accents per language. Back to the
[README](../README.md).

## Setting the language

Each index analyses its text in one language:

```php
#[Searchable(language: 'hungarian')]                            // attribute
IndexDefinition::builder('termekek')->language('hungarian')     // builder
```

```yaml
language: hungarian     # YAML; add "unaccent: false" to keep accents significant
```

The value is a PostgreSQL text search configuration. PostgreSQL 15+ ships 29: `arabic`,
`armenian`, `basque`, `catalan`, `danish`, `dutch`, `english` (the default), `finnish`, `french`,
`german`, `greek`, `hindi`, `hungarian`, `indonesian`, `irish`, `italian`, `lithuanian`, `nepali`,
`norwegian`, `portuguese`, `romanian`, `russian`, `serbian`, `spanish`, `swedish`, `tamil`,
`turkish`, `yiddish` (each with a Snowball stemmer), and `simple` (no stemming, no stop words: for
codes, names or mixed-language text).

## What the language decides

- Stemming: word forms are reduced to a common stem, so a search for one form finds the others.
- Stop words: the language's filler words ("the", "for", "und", and accented ones such as "für",
  "és" or "à") are ignored.
- Accent folding (on by default): `fuzzphony:schema --apply` creates a configuration
  `fuzzphony_<language>` that copies the built-in one and removes accents before stemming, so
  `cafe` finds `café`. Stop words are dropped before the accents are removed, with a dictionary
  `fuzzphony_<language>_stop` that uses the built-in stop-word list, so folding never turns one
  into an ordinary word. Languages whose stemmer has no stop-word list get no such dictionary.

Measured with Fuzzphony's configuration (accents removed, then stemmed):

| Language | These find each other |
|---|---|
| english | `running`, `run` · `drills`, `drill` |
| german | `Häuser`, `Haus` · `Mäuse`, `Maus` · `Bücher`, `Buch` |
| french | `chevaux`, `cheval` · `crèmes`, `crème` |
| spanish | `canciones`, `canción` |
| hungarian | `házak`, `ház` · `könyvek`, `könyv` · `egerek`, `egér` (only with accent folding) |

Typo tolerance (trigram similarity) works the same in every language.

## Limits

- Stemmers reduce regular endings only. Irregular forms such as `mice` / `mouse` or `went` / `go`
  stay different words.
- One language per index. For a multilingual catalogue, build one index per language (for example
  `->fromQuery("SELECT ... FROM product WHERE lang = 'de'")->language('german')`) and search the
  one that matches the user's locale. The demo's Languages page does this.
- A custom configuration you installed yourself (a Hunspell dictionary, say) works with
  `unaccent: false`. With accent folding, Fuzzphony pairs the name with the built-in
  `<language>_stem` dictionary, so it must be one of the configurations above.
