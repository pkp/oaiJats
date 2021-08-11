import sys
from lxml import etree
from lxml import isoschematron

if (len(sys.argv) != 3):
    print("Usage: validate.py subject.xml schema.sch")
    exit(2);

# Load the schematron schema.
sct_doc = etree.parse(sys.argv[2])
schematron = isoschematron.Schematron(sct_doc)

# Load and validate the XML document.
doc = etree.parse(sys.argv[1])
if not schematron.validate(doc):
    # Invalid
    print("Could not validate XML using Schematron rules!")
    sys.exit(1);
else:
    # Valid
    sys.exit(0);
