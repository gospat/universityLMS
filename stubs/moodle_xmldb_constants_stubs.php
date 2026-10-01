<?php
/**
 * Intelephense stub file for Moodle xmldb constants and classes.
 * This file is NEVER executed by Moodle runtime (MOODLE_INTERNAL check at top).
 * It exists solely to provide type information for static analysis.
 *
 * At runtime Moodle defines these inside lib/xmldb/xmldb_constants.php and
 * lib/xmldb/xmldb_object.class.php (loaded by xmldbupgrade.lib.php).
 *
 * This file lives outside Moodle's class autoload paths and guards itself with
 * MOODLE_INTERNAL, so it will never collide with the real runtime definitions.
 */
if (!defined('MOODLE_INTERNAL')) {
    // Die immediately if accessed via web or CLI outside Moodle bootstrap.
    die;
}

// ----------------------------------------------------------------------------
// XMLDB field / column type constants
// ----------------------------------------------------------------------------
if (!defined('XMLDB_TYPE_INTEGER'))  { define('XMLDB_TYPE_INTEGER',  'integer'); }
if (!defined('XMLDB_TYPE_NUMBER'))   { define('XMLDB_TYPE_NUMBER',   'number');  }
if (!defined('XMLDB_TYPE_FLOAT'))    { define('XMLDB_TYPE_FLOAT',    'float');   }
if (!defined('XMLDB_TYPE_CHAR'))     { define('XMLDB_TYPE_CHAR',     'char');    }
if (!defined('XMLDB_TYPE_TEXT'))     { define('XMLDB_TYPE_TEXT',     'text');    }
if (!defined('XMLDB_TYPE_BINARY'))   { define('XMLDB_TYPE_BINARY',   'binary');  }
if (!defined('XMLDB_TYPE_DATETIME')) { define('XMLDB_TYPE_DATETIME', 'datetime');}
if (!defined('XMLDB_ENUM'))          { define('XMLDB_ENUM',          'enum');    }

// ----------------------------------------------------------------------------
// XMLDB field attribute / modifier constants
// ----------------------------------------------------------------------------
if (!defined('XMLDB_NOTNULL'))  { define('XMLDB_NOTNULL',  1); }
if (!defined('XMLDB_SEQUENCE')) { define('XMLDB_SEQUENCE', 2); }
if (!defined('XMLDB_UNSIGNED')) { define('XMLDB_UNSIGNED', 4); }

// ----------------------------------------------------------------------------
// XMLDB key (constraint) type constants
// ----------------------------------------------------------------------------
if (!defined('XMLDB_KEY_PRIMARY'))   { define('XMLDB_KEY_PRIMARY',   1); }
if (!defined('XMLDB_KEY_UNIQUE'))    { define('XMLDB_KEY_UNIQUE',    2); }
if (!defined('XMLDB_KEY_FOREIGN'))   { define('XMLDB_KEY_FOREIGN',   3); }
if (!defined('XMLDB_KEY_UNIQUE_FK')) { define('XMLDB_KEY_UNIQUE_FK', 4); }

// ----------------------------------------------------------------------------
// XMLDB key ON DELETE action constants
// ----------------------------------------------------------------------------
if (!defined('XMLDB_KEY_CASCADE'))  { define('XMLDB_KEY_CASCADE',  'CASCADE');  }
if (!defined('XMLDB_KEY_SETNULL'))  { define('XMLDB_KEY_SETNULL',  'SET NULL'); }
if (!defined('XMLDB_KEY_RESTRICT')) { define('XMLDB_KEY_RESTRICT', 'RESTRICT'); }
if (!defined('XMLDB_KEY_NONE'))     { define('XMLDB_KEY_NONE',     '');         }

// ----------------------------------------------------------------------------
// XMLDB index type constants
// ----------------------------------------------------------------------------
if (!defined('XMLDB_INDEX_NOTUNIQUE')) { define('XMLDB_INDEX_NOTUNIQUE', 0); }
if (!defined('XMLDB_INDEX_UNIQUE'))    { define('XMLDB_INDEX_UNIQUE',    1); }

// ----------------------------------------------------------------------------
// XMLDB class stubs.  These exist only for static-analysis type resolution.
// Moodle's real xmldb classes are loaded from lib/xmldb/ classes and are never
// autoloaded from this stubs/ folder.
// ----------------------------------------------------------------------------
class xmldb_object {
    /** @var string */
    protected $name;
    /** @var bool */
    protected $loaded;
    /** @var string */
    protected $comment;
    /** @var array */
    protected $previous;
    /** @var bool */
    protected $changed;

    public function __construct(?string $name = null) {}

    public function setName(string $name): void { $this->name = $name; }
    public function getName(): string { return (string)$this->name; }

    public function setComment(string $comment): void { $this->comment = $comment; }
    public function getComment(): string { return (string)$this->comment; }

    public function setLoaded(bool $loaded): void { $this->loaded = $loaded; }
    public function getLoaded(): bool { return (bool)$this->loaded; }

    public function setChanged(bool $changed): void { $this->changed = $changed; }
    public function getChanged(): bool { return (bool)$this->changed; }
}

class xmldb_table extends xmldb_object {
    /** @var xmldb_field[] */
    protected $fields = [];
    /** @var xmldb_key[] */
    protected $keys = [];
    /** @var xmldb_index[] */
    protected $indexes = [];
    /** @var string */
    protected $options;

    /**
     * @param string $name
     * @param string $type
     * @param string|null $length
     * @param string|null $decimals
     * @param bool|null $notnull
     * @param bool|null $sequence
     * @param mixed $default
     * @param string|null $previous
     * @return xmldb_field
     */
    public function add_field(string $name, string $type, ?string $length = null, ?string $decimals = null,
                              ?bool $notnull = null, ?bool $sequence = null, $default = null,
                              ?string $previous = null): xmldb_field {
        $f = new xmldb_field($name, $type, $length, $decimals, $notnull, $sequence, $default, $previous);
        $this->fields[] = $f;
        return $f;
    }

    /**
     * @param string $name
     * @param string $type
     * @param string[] $fields
     * @param string|null $reftable
     * @param string[] $reffields
     * @return xmldb_key
     */
    public function add_key(string $name, string $type, array $fields = [],
                            ?string $reftable = null, array $reffields = []): xmldb_key {
        $k = new xmldb_key($name, $type, $fields, $reftable, $reffields);
        $this->keys[] = $k;
        return $k;
    }

    /**
     * @param string $name
     * @param string $type
     * @param string[] $fields
     * @return xmldb_index
     */
    public function add_index(string $name, string $type, array $fields = []): xmldb_index {
        $i = new xmldb_index($name, $type, $fields);
        $this->indexes[] = $i;
        return $i;
    }

    /** @return xmldb_field[] */
    public function getFields(): array { return $this->fields; }

    /** @return xmldb_key[] */
    public function getKeys(): array { return $this->keys; }

    /** @return xmldb_index[] */
    public function getIndexes(): array { return $this->indexes; }
}

class xmldb_field extends xmldb_object {
    /** @var string */
    protected $type;
    /** @var string|null */
    protected $length;
    /** @var string|null */
    protected $decimals;
    /** @var bool|null */
    protected $notnull;
    /** @var bool|null */
    protected $sequence;
    /** @var string|null */
    protected $default;
    /** @var string|null */
    protected $previous;

    /**
     * @param string|null $name
     * @param string|null $type
     * @param string|null $length
     * @param string|null $decimals
     * @param bool|null $notnull
     * @param bool|null $sequence
     * @param mixed $default
     * @param string|null $previous
     */
    public function __construct(?string $name = null, ?string $type = null, ?string $length = null,
                                ?string $decimals = null, ?bool $notnull = null,
                                ?bool $sequence = null, $default = null,
                                ?string $previous = null) {
        if ($name !== null) { $this->name = $name; }
        if ($type !== null) { $this->type = $type; }
        if ($length !== null) { $this->length = $length; }
        if ($decimals !== null) { $this->decimals = $decimals; }
        if ($notnull !== null) { $this->notnull = $notnull; }
        if ($sequence !== null) { $this->sequence = $sequence; }
        if (func_num_args() >= 7) { $this->default = $default; }
        if ($previous !== null) { $this->previous = $previous; }
    }

    public function setType(string $type): void { $this->type = $type; }
    public function getType(): ?string { return $this->type; }

    public function setLength(?string $length): void { $this->length = $length; }
    public function getLength(): ?string { return $this->length; }

    public function setDecimals(?string $decimals): void { $this->decimals = $decimals; }
    public function getDecimals(): ?string { return $this->decimals; }

    public function setNotnull(?bool $notnull): void { $this->notnull = $notnull; }
    public function getNotnull(): ?bool { return $this->notnull; }

    public function setSequence(?bool $sequence): void { $this->sequence = $sequence; }
    public function getSequence(): ?bool { return $this->sequence; }

    /** @param mixed $default */
    public function setDefault($default): void { $this->default = $default; }

    /** @return mixed */
    public function getDefault() { return $this->default; }
}

class xmldb_key extends xmldb_object {
    /** @var string */
    protected $type;
    /** @var array */
    protected $fields = [];
    /** @var string|null */
    protected $reftable;
    /** @var array */
    protected $reffields = [];
    /** @var string */
    protected $on_delete;

    /**
     * @param string|null $name
     * @param string|null $type
     * @param string[] $fields
     * @param string|null $reftable
     * @param string[] $reffields
     */
    public function __construct(?string $name = null, ?string $type = null, array $fields = [],
                                ?string $reftable = null, array $reffields = []) {
        if ($name !== null) { $this->name = $name; }
        if ($type !== null) { $this->type = $type; }
        $this->fields = $fields;
        $this->reftable = $reftable;
        $this->reffields = $reffields;
    }

    public function setType(string $type): void { $this->type = $type; }
    public function getType(): ?string { return $this->type; }

    /** @param string[] $fields */
    public function setFields(array $fields): void { $this->fields = $fields; }

    /** @return string[] */
    public function getFields(): array { return $this->fields; }

    public function setReftable(?string $reftable): void { $this->reftable = $reftable; }
    public function getReftable(): ?string { return $this->reftable; }

    /** @param string[] $reffields */
    public function setReffields(array $reffields): void { $this->reffields = $reffields; }

    /** @return string[] */
    public function getReffields(): array { return $this->reffields; }

    /**
     * Sets the ON DELETE action for the foreign key.
     *
     * @param string $action One of XMLDB_KEY_CASCADE, XMLDB_KEY_SETNULL, XMLDB_KEY_RESTRICT, XMLDB_KEY_NONE.
     * @return void
     */
    public function set_on_delete(string $action): void { $this->on_delete = $action; }

    public function get_on_delete(): string { return (string)($this->on_delete ?? ''); }
}

class xmldb_index extends xmldb_object {
    /** @var string */
    protected $type;
    /** @var array */
    protected $fields = [];

    /**
     * @param string|null $name
     * @param string|null $type
     * @param string[] $fields
     */
    public function __construct(?string $name = null, ?string $type = null, array $fields = []) {
        if ($name !== null) { $this->name = $name; }
        if ($type !== null) { $this->type = $type; }
        $this->fields = $fields;
    }

    public function setType(string $type): void { $this->type = $type; }
    public function getType(): ?string { return $this->type; }

    /** @param string[] $fields */
    public function setFields(array $fields): void { $this->fields = $fields; }

    /** @return string[] */
    public function getFields(): array { return $this->fields; }
}
