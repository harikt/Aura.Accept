<?php
namespace Aura\Web\Request\Accept;

use Aura\Web\Request\Accept\Value\ValueFactory;
use IteratorAggregate;

abstract class AbstractValues implements IteratorAggregate
{
    protected $acceptable = array();

    protected $server_key;
    
    protected $value_type;
    
    /**
     * @param array $server A copy of $_SERVER.
     */
    public function __construct(
        ValueFactory $value_factory,
        array $server = array()
    ) {
        $this->value_factory = $value_factory;
        $this->add($server);
    }
    
    public function get($key = null)
    {
        if ($key === null) {
            return $this->acceptable;
        }
        return $this->acceptable[$key];
    }
    
    protected function set($values)
    {
        $this->acceptable = array();
        $this->add($values);
    }
    
    /**
     * @param string|array $values $_SERVER of an Accept* value
     */
    protected function add($values)
    {
        $key = $this->server_key;
        
        if (is_array($values)) {
            if (! isset($values[$key])) {
                $this->acceptable = array();
                return;
            }
            $values = $values[$key];
        }

        $values = $this->parseAcceptable($values, $key);
        $values = $this->qualitySort(array_merge($this->acceptable, $values));

        $values = $this->removeDuplicates($values);

        $this->acceptable = $values;
    }

    protected function parseAcceptable($values)
    {
        $values = explode(',', $values);

        foreach ($values as $key => $value) {
            $pairs = explode(';', $value);
            $value = $pairs[0];
            unset($pairs[0]);

            $params = array();
            foreach ($pairs as $pair) {
                $param = array();
                preg_match('/^(?P<name>.+?)=(?P<quoted>"|\')?(?P<value>.*?)(?:\k<quoted>)?$/', $pair, $param);

                $params[$param['name']] = $param['value'];
            }

            $quality = 1.0;
            if (isset($params['q'])) {
                $quality = $params['q'];
                unset($params['q']);
            }

            $values[$key] = $this->value_factory->newInstance(
                $this->value_type,
                trim($value),
                (float) $quality,
                $params
            );
        }

        return $values;
    }

    /**
     * 
     * Sorts an Accept header value set according to quality levels.
     * 
     * This is an unusual sort. Normally we'd think a reverse-sort would
     * order the array by q values from 1 to 0, but the problem is that
     * an implicit 1.0 on more than one value means that those values will
     * be reverse from what the header specifies, which seems unexpected
     * when negotiating later.
     * 
     * @param array $server An array of $_SERVER values.
     * 
     * @param string $key The key to look up in $_SERVER.
     * 
     * @return array An array of values sorted by quality level.
     * 
     */
    protected function qualitySort($values)
    {
        $var    = array();
        $bucket = array();

        // sort into q-value buckets
        foreach ($values as $value) {
            $bucket[$value->getQuality()][] = $value;
        }

        // reverse-sort the buckets so that q=1 is first and q=0 is last,
        // but the values in the buckets stay in the original order.
        krsort($bucket);

        // flatten the buckets into the var
        foreach ($bucket as $q => $values) {
            foreach ($values as $value) {
                $var[] = $value;
            }
        }

        return $var;
    }

    protected function removeDuplicates($values)
    {
        $unique = array();
        foreach ($values as $value) {
            $unique[$value->getValue()] = $value;
        }

        return array_values($unique);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->acceptable);
    }

    protected function convertAvailable(array $available)
    {
        $values = clone $this;
        $values->set(array());
        foreach ($available as $avail) {
            $values->add($avail);
        }
        return $values;
    }
    
    /**
     * 
     * Returns a value negotiated between acceptable and available values.
     * 
     * @param array $available Available values in preference order, if any.
     * 
     * @return mixed The header values as an array, or the negotiated value
     * (false indicates negotiation failed).
     * 
     * @todo figure out what to do when matching to * when the result has an explicit q=0 value.
     * 
     */
    public function negotiate(array $available = null)
    {
        // if none available, no possible match
        if (! $available) {
            return false;
        }

        // convert to object
        $available = $this->convertAvailable($available);
        
        // if nothing acceptable specified, use first available
        if (! $this->acceptable) {
            return $available->get(0);
        }

        // loop through acceptable values
        foreach ($this->acceptable as $accept) {
            
            // if the acceptable quality is zero, skip it
            if ($accept->getQuality() == 0) {
                continue;
            }
            
            // if acceptable value is "anything" return the first available
            if ($accept->isWildcard()) {
                return $available->get(0);
            }
            
            // if acceptable value is available, use it
            foreach ($available as $avail) {
                if ($accept->match($avail)) {
                    return $avail;
                }
            }
        }
        
        return false;
    }
}
