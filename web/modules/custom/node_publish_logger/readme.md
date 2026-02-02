Create a custom module that will log message when node is published.

Requirement
    1. Log the informational message only if the node's body contains these restricted words: Toptal, Drupal, Developer.
    2. The string comparison should be case insensitive.
    3. The logged message should have the following details: the title of the published node, the type of action (created/updated), the matched word, and the link to the node e.g. "Node 'First article' has been updated with the word 'Drupal' inside its body."