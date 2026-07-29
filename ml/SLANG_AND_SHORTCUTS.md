# Common Tagalog/Taglish Shortcuts and Slang

The SVM component includes normalization and training examples for informal feedback commonly typed on phones or social media.

## Shortened words

| Typed variant | Normalized form |
|---|---|
| nmn | naman |
| dba / db | di ba |
| di / ndi / hndi / hnd | hindi |
| kc / kse / ksa | kasi |
| lng | lang |
| kht | kahit |
| mdami | marami |
| aq | ako |
| q | ko |
| sna | sana |
| tnx / thx | thanks |
| ty / tyvm | thank you |
| pls / plz | please |

## Common slang

| Slang | Typical interpretation |
|---|---|
| oks / okey | okay |
| goods | maganda or maayos |
| solid | very good |
| angas | impressive or good |
| ambait | ang bait |
| ambilis | ang bilis |
| ambagal | ang bagal |
| waley | wala or poor |
| badtrip | nakakainis |
| dedma | hindi pinapansin |
| keri | manageable or okay |
| meh / mid | average or neutral |
| hassle | abala or difficult |

## Elongated spellings

The normalizer also reduces excessive repeated letters, including examples such as:

- `bagaaal` → `bagal`
- `tagaaal` → `tagal`
- `bilisss` → `bilis`
- `ayooos` → `ayos`
- `pangettt` → `pangit`

## Important reminder

A shortcut or slang term does not always carry one fixed sentiment. For example, `okay` may be positive or neutral depending on the full comment. The model classifies the complete sentence and its word/character patterns, not only a single keyword.
