<?php

namespace NickPotts\Slice\Engine\Grammar;

class MariaDbGrammar extends MySqlGrammar
{
    // MariaDB uses the same syntax as MySQL for time bucketing
    // Extending MySqlGrammar provides all functionality
    // This class exists for future MariaDB-specific optimizations
}
