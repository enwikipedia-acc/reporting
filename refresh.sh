#!/bin/bash

find -type l -not -name refresh.sh -delete;
for f in $(find ../report -maxdepth 1 -type f -name \*.html | sed -e 's@../report/@@' -e 's/.html//'); do 
    tmp=$(mktemp -u -p . -t $f-XXXXXXXX.html);
    ln -s ../report/$f.html $tmp;
    echo "https://accounts-dev.wmflabs.org/r/$tmp" | sed -e 's#/\./#/#';
done

for f in $(find ../report -maxdepth 1 -type f -name \*.js | sed -e 's@../report/@@' -e 's/.js//'); do 
    ln -s ../report/$f.js $f.js;
done
