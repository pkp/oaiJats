#!/bin/bash

set -e

# Add python libraries
sudo apt update
sudo apt install build-essential zlib1g-dev libncurses5-dev libgdbm-dev libnss3-dev libssl-dev libsqlite3-dev libreadline-dev libffi-dev wget libbz2-dev
sudo add-apt-repository ppa:deadsnakes/ppa -y
sudo apt install python3.7
sudo apt-get install python3-lxml xmlstarlet
pip install lxml


git clone -b ${APP_BRANCH} https://github.com/pkp/jatsTemplate plugins/generic/jatsTemplate
php lib/pkp/tools/installPluginVersion.php plugins/generic/jatsTemplate/version.xml
php lib/pkp/tools/installPluginVersion.php plugins/oaiMetadataFormats/oaiJats/version.xml

echo "Run cypress tests"
npx cypress run  --config integrationFolder=plugins/oaiMetadataFormats/oaiJats/cypress/tests
wget -q -O - "http://localhost/index.php/publicknowledge/oai?verb=ListRecords&metadataPrefix=jats" | xmlstarlet sel -N x="https://jats.nlm.nih.gov/publishing/1.1/" -t -c "(//x:article)[1]" > jats.xml
wget -q "https://eruditps.docs.erudit.org/_downloads/f0f9fb861e01a47df2ce48f588524d29/erudit-style-0.3.sch"

echo "Validate  against erudit-style"
python3 plugins/oaiMetadataFormats/oaiJats/validate.py jats.xml erudit-style-0.3.sch