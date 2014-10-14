<?php

namespace Hautelook\AliceBundle\Alice;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\ObjectManager;
use Nelmio\Alice\ProcessorInterface;
use Psr\Log\LoggerInterface;

/**
 * Loader
 * @author Baldur Rensch <brensch@gmail.com>
 */
class Loader
{
    /**
     * @var array
     */
    private $providers;

    /**
     * @var ProcessorInterface[]
     */
    private $processors;

    /**
     * @var array
     */
    private $loaders;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Doctrine
     */
    private $persister;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ArrayCollection
     */
    private $references;

    /**
     * @param                 $loaders
     * @param LoggerInterface $logger
     */
    public function __construct($loaders, LoggerInterface $logger = null)
    {
        $this->loaders = $loaders;
        $this->processors = array();
        $this->logger = $logger;
        $this->references = new ArrayCollection();
    }

    /**
     * @param ObjectManager $manager
     */
    public function setObjectManager(ObjectManager $manager)
    {
        $this->objectManager = $manager;

        $this->persister = new Doctrine($this->objectManager);

        $newReferences = array();
        foreach ($this->references as $name => $reference) {
            // Don't merge value objects, e.g. Doctrine embeddables
            $metadata = $manager->getClassMetadata(get_class($reference));
            if (count($metadata->getIdentifier()) > 0) {
                $reference = $this->persister->merge($reference);
            }

            $newReferences[$name] = $reference;
        }
        $this->references = new ArrayCollection($newReferences);

        /** @var $loader \Nelmio\Alice\Loader\Base */
        foreach ($this->loaders as $loader) {
            $loader->setLogger($this->logger);
            $loader->setORM($this->persister);
            $loader->setReferences($newReferences);
        }
    }

    /**
     * @param array<string> $files
     *
     * @return ArrayCollection References
     */
    public function load(array $files)
    {
        /** @var $loader \Nelmio\Alice\Loader\Base */
        $loader = $this->getLoader('yaml');
        $loader->setProviders($this->providers);

        $objects = array();
        foreach ($files as $file) {
            $set = $loader->load($file);
            $this->persist($set);

            $objects = array_merge($objects, $set);
        }

        foreach ($loader->getReferences() as $name => $obj) {
            $this->persister->detach($obj);
            $this->references->set($name, $obj);
        }

        // remove processors when file is loaded
        $this->processors = array();

        return $this->references;
    }

    /**
     * @param array $providers
     */
    public function setProviders(array $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @param ProcessorInterface $processor
     */
    public function addProcessor(ProcessorInterface $processor)
    {
        $this->processors[] = $processor;
    }

    /**
     * @param string $key
     *
     * @throws \InvalidArgumentException
     * @return mixed
     */
    protected function getLoader($key)
    {
        if (empty($this->loaders[$key])) {
            throw new \InvalidArgumentException("Unknown loader type: {$key}");
        }
        /*
        if (is_string($file) && preg_match('{\.ya?ml(\.php)?$}', $file)) {
            $loader = self::getLoader('Yaml', $options);
        } elseif ((is_string($file) && preg_match('{\.php$}', $file)) || is_array($file)) {
            $loader = self::getLoader('Base', $options);
        } else {
            throw new \InvalidArgumentException('Unknown file/data type: '.gettype($file).' ('.json_encode($file).')');
        }
        */
        /** @var $loader \Nelmio\Alice\LoaderInterface */
        $loader = $this->loaders[$key];

        return $loader;
    }

    /**
     * Persists objects with the preProcess and postProcess methods used by the processors.
     *
     * @param $objects
     */
    private function persist($objects)
    {
        foreach ($this->processors as $processor) {
            foreach ($objects as $obj) {
                $processor->preProcess($obj);
            }
        }

        $this->persister->persist($objects);

        foreach ($this->processors as $processor) {
            foreach ($objects as $obj) {
                $processor->postProcess($obj);
            }
        }
    }
}
