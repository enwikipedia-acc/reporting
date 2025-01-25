## Experimental request analysis tool

This is a **purely-experimental** set of scripts to automatically evaluate open requests to flag specific checks for human review. 
This is vaguely progress for https://github.com/enwikipedia-acc/waca/issues/425 , but not integrated into the UI yet, and reliant on database queries and API calls in a single batch.
It is my hope that this can eventually be refactored into a set of functions which take a request ID and return a result, and that this can be cached in the main schema and retrieved on-demand.
