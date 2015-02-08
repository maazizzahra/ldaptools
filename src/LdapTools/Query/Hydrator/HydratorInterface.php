<?php
/**
 * This file is part of the LdapTools package.
 *
 * (c) Chad Sikorra <Chad.Sikorra@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LdapTools\Query\Hydrator;

use LdapTools\Schema\LdapObjectSchema;

/**
 * LDAP entry hydrators must implement this interface.
 *
 * @author Chad Sikorra <Chad.Sikorra@gmail.com>
 */
interface HydratorInterface
{
    /**
     * Hydrates an array representing a LDAP object so it can be sent back to LDAP.
     *
     * @param mixed $object
     * @return array
     */
    public function hydrateToLdap($object);

    /**
     * Hydrates a single LDAP entry.
     *
     * @param array $entry
     * @return mixed
     */
    public function hydrateFromLdap(array $entry);

    /**
     * Hydrates an array of LDAP entries.
     *
     * @param array $entries
     * @return mixed
     */
    public function hydrateAllFromLdap(array $entries);

    /**
     * Set the LdapObjectSchema objects needed to attribute/value conversion.
     *
     * @param LdapObjectSchema ...$ldapObjectSchema
     */
    public function setLdapObjectSchemas(LdapObjectSchema ...$ldapObjectSchema);

    /**
     * Get the LdapObjectSchema objects that have been set.
     *
     * @return array An array of LdapObjectSchema objects.
     */
    public function getLdapObjectSchemas();

    /**
     * If this query is based off a LdapObjectSchema then include the attributes as they were named in the select
     * statement. How they were named in the select statement is how they will be passed back. This also means that any
     * converters applied to the mapped name, but not to the LDAP attribute name, will not be applied.
     *
     * @param array $attributes
     */
    public function setSelectedAttributes(array $attributes);

    /**
     * Get the attributes that were selected for in the query.
     *
     * @return array
     */
    public function getSelectedAttributes();
}
