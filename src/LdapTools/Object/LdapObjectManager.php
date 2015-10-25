<?php
/**
 * This file is part of the LdapTools package.
 *
 * (c) Chad Sikorra <Chad.Sikorra@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LdapTools\Object;

use LdapTools\AttributeConverter\AttributeConverterInterface;
use LdapTools\BatchModify\BatchCollection;
use LdapTools\Connection\LdapConnectionInterface;
use LdapTools\Event\Event;
use LdapTools\Event\EventDispatcherInterface;
use LdapTools\Event\LdapObjectEvent;
use LdapTools\Factory\HydratorFactory;
use LdapTools\Factory\LdapObjectSchemaFactory;
use LdapTools\Query\LdapQueryBuilder;

/**
 * Handles updates and deletes to LDAP based off passed object data.
 *
 * @author Chad Sikorra <Chad.Sikorra@gmail.com>
 */
class LdapObjectManager
{
    /**
     * @var LdapConnectionInterface
     */
    protected $connection;

    /**
     * @var LdapObjectSchemaFactory
     */
    protected $schemaFactory;

    /**
     * @var HydratorFactory
     */
    protected $hydratorFactory;

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @param LdapConnectionInterface $connection
     * @param LdapObjectSchemaFactory $schemaFactory
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(LdapConnectionInterface $connection, LdapObjectSchemaFactory $schemaFactory, EventDispatcherInterface $dispatcher)
    {
        $this->hydratorFactory = new HydratorFactory();
        $this->schemaFactory = $schemaFactory;
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Updates an object in LDAP. It will only update attributes that actually changed on the object.
     *
     * @param LdapObject $ldapObject
     */
    public function persist(LdapObject $ldapObject)
    {
        if (empty($ldapObject->getBatchCollection()->toArray())) {
            return;
        }
        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_BEFORE_MODIFY, $ldapObject));

        $this->validateObject($ldapObject);
        $this->connection->modifyBatch($ldapObject->get('dn'), $this->getLdapObjectBatchArray($ldapObject));
        $ldapObject->setBatchCollection(new BatchCollection($ldapObject->get('dn')));

        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_AFTER_MODIFY, $ldapObject));
    }

    /**
     * Removes an object from LDAP.
     *
     * @param LdapObject $ldapObject
     */
    public function delete(LdapObject $ldapObject)
    {
        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_BEFORE_DELETE, $ldapObject));
        $this->validateObject($ldapObject);
        $this->connection->delete($ldapObject->get('dn'));
        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_AFTER_DELETE, $ldapObject));
    }

    /**
     * Moves an object from one container/OU to another in LDAP.
     *
     * @param LdapObject $ldapObject
     * @param string $container
     */
    public function move(LdapObject $ldapObject, $container)
    {
        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_BEFORE_MOVE, $ldapObject));
        $this->validateObject($ldapObject);
        $rdn = $this->getRdnFromLdapObject($ldapObject);
        $this->connection->move($ldapObject->get('dn'), $rdn, $container);

        $newDn = $rdn.','.$container;
        $ldapObject->refresh(['dn' => $newDn]);
        $ldapObject->getBatchCollection()->setDn($newDn);
        $this->dispatcher->dispatch(new LdapObjectEvent(Event::LDAP_OBJECT_AFTER_MOVE, $ldapObject));
    }

    /**
     * The DN attribute must be present to perform LDAP operations.
     *
     * @param LdapObject $ldapObject
     */
    protected function validateObject(LdapObject $ldapObject)
    {
        if (!$ldapObject->has('dn')) {
            throw new \InvalidArgumentException('To persist/delete a LDAP object it must have the DN attribute.');
        }
    }

    /**
     * Gets the RDN for a LDAP object schema type. It should be mapped to the "name" attribute. This does not handle
     * multi-valued RDNs. Though I'm not too sure how that should really be implemented either.
     *
     * @param LdapObject $ldapObject
     * @return string
     */
    protected function getRdnFromLdapObject(LdapObject $ldapObject)
    {
        if (empty($ldapObject->getType())) {
            throw new \InvalidArgumentException('The LDAP object must have a schema type defined to perform this action.');
        }

        $schema = $this->schemaFactory->get($this->connection->getSchemaName(), $ldapObject->getType());
        if (!$schema->hasAttribute('name')) {
            throw new \InvalidArgumentException(sprintf(
                'The LdapObject type "%s" needs a "name" attribute defined that references the RDN.',
                $ldapObject->getType()
            ));
        }
        $name = $ldapObject->has('name') ? $ldapObject->get('name') : $this->getRdnValueIfNotSelected($ldapObject);

        return $schema->getAttributeToLdap('name').'='.ldap_escape($name, null, LDAP_ESCAPE_DN);
    }

    /**
     * If for some reason the "name" attribute was not selected when the LdapObject was instantiated, then try to
     * query its value here.
     *
     * @param LdapObject $ldapObject
     * @return string
     */
    protected function getRdnValueIfNotSelected(LdapObject $ldapObject)
    {
        /** @var LdapObject $result */
        $result = (new LdapQueryBuilder($this->connection, $this->schemaFactory))->select('name')
            ->from($ldapObject->getType())
            ->where(['dn' => $ldapObject->get('dn')])
            ->getLdapQuery()
            ->getOneOrNullResult();

        if (is_null($result) || !$result->has('name')) {
            throw new \RuntimeException('Unable to retrieve the RDN value for the LdapObject');
        }

        return $result->get('name');
    }

    /**
     * Get the batch modification array that ldap_modify_batch expects.
     *
     * @param LdapObject $ldapObject
     * @return array
     */
    protected function getLdapObjectBatchArray(LdapObject $ldapObject)
    {
        $hydrator = $this->hydratorFactory->get(HydratorFactory::TO_OBJECT);
        $hydrator->setLdapConnection($this->connection);
        $hydrator->setOperationType(AttributeConverterInterface::TYPE_MODIFY);

        if ($ldapObject->getType()) {
            $schema = $this->schemaFactory->get($this->connection->getSchemaName(), $ldapObject->getType());
            $hydrator->setLdapObjectSchemas($schema);
        }

        return $hydrator->hydrateToLdap($ldapObject);
    }
}
