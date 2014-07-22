Expanded Multi-answer with Moodle@BU Question Types
-------------

This expanded Cloze question type works very much like the normal multi-answer question type, but allows several additional question types to be entered, and allows scripting of question details with Lua.

In the distant future, it should be replaced by a nicer, unified MultiAnswer question type, which will be generalized to support all question types with an embeddable interface.

Installlation
--------------

To install Moodle 2.1+ using git, execute the following commands in the root of your Moodle install:

    git clone git://github.com/bumoodle/moodle-qtype_multianswerbu.git question/type/multianswerbu
    echo '/question/type/multianswerbu' >> .git/info/exclude
            
Or, extract the following zip in your_moodle_root/question/type/:
            
    https://github.com/bumoodle/moodle-qtype_multianswerbu/zipball/master
