<?php namespace Maclof\Kubernetes;

use BadMethodCallException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ParseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Maclof\Kubernetes\Collections\NodeCollection;
use Maclof\Kubernetes\Collections\PodCollection;
use Maclof\Kubernetes\Collections\ReplicationControllerCollection;
use Maclof\Kubernetes\Collections\ServiceCollection;
use Maclof\Kubernetes\Collections\SecretCollection;
use Maclof\Kubernetes\Models\Node;
use Maclof\Kubernetes\Models\Pod;
use Maclof\Kubernetes\Models\ReplicationController;
use Maclof\Kubernetes\Models\Service;
use Maclof\Kubernetes\Models\Secret;
use Maclof\Kubernetes\Models\Deployment;
use Maclof\Kubernetes\Models\Job;
use Maclof\Kubernetes\Exceptions\BadRequestException;
use Maclof\Kubernetes\Exceptions\MissingOptionException;

class Client
{
	/**
	 * The api version.
	 *
	 * @var string
	 */
	protected $apiVersion = 'v1';

	/**
	 * The beta api version.
	 *
	 * @var string
	 */
	protected $betaApiVersion = 'extensions/v1beta1';

	/**
	 * The address of the master server.
	 *
	 * @var string|null
	 */
	protected $master;

	/**
	 * The ca certificate.
	 *
	 * @var string|null
	 */
	protected $caCert;

	/**
	 * The client certificate.
	 *
	 * @var string|null
	 */
	protected $clientCert;

	/**
	 * The client key.
	 *
	 * @var string|null
	 */
	protected $clientKey;

	/**
	 * The token.
	 *
	 * @var string
	 */
	protected $token;

	/**
	 * The namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'default';

	/**
	 * The http client.
	 *
	 * @var \GuzzleHttp\Client|null
	 */
	protected $guzzleClient;

	/**
	 * The class map.
	 *
	 * @var array
	 */
	protected $classMap = [
		// v1
		'nodes'                  => 'Repositories\NodeRepository',
		'pods'                   => 'Repositories\PodRepository',
		'replicaSets'            => 'Repositories\ReplicaSetRepository',
		'replicationControllers' => 'Repositories\ReplicationControllerRepository',
		'services'               => 'Repositories\ServiceRepository',
		'secrets'                => 'Repositories\SecretRepository',
		'events'                 => 'Repositories\EventRepository',
		'configMaps'             => 'Repositories\ConfigMapRepository',
		
		// extensions/v1beta1
		'deployments'            => 'Repositories\DeploymentRepository',
		'jobs'                   => 'Repositories\JobRepository',
		'ingresses'              => 'Repositories\IngressRepository',
	];

	/**
	 * The class instances.
	 *
	 * @var array
	 */
	protected $classInstances = [];

	/**
	 * The constructor.
	 *
	 * @param array $options
	 * @param \GuzzleHttp\Client $guzzleClient
	 */
	public function __construct(array $options = array(), GuzzleClient $guzzleClient = null)
	{
		$this->setOptions($options);
		$this->guzzleClient = $guzzleClient ? $guzzleClient : $this->createGuzzleClient();
	}

	/**
	 * Set the options.
	 *
	 * @param array $options
	 */
	public function setOptions(array $options)
	{
		if (!isset($options['master'])) {
			throw new MissingOptionException('You must provide a "master" parameter.');
		}
		$this->master = $options['master'];

		if (isset($options['ca_cert'])) {
			$this->caCert = $options['ca_cert'];
		}
		if (isset($options['client_cert'])) {
			$this->clientCert = $options['client_cert'];
		}
		if (isset($options['client_key'])) {
			$this->clientKey = $options['client_key'];
		}
		if (isset($options['token'])) {
			$this->token = $options['token'];
		}
		if (isset($options['namespace'])) {
			$this->namespace = $options['namespace'];
		}
	}

	/**
	 * Check if we're using guzzle 6.
	 * 
	 * @return boolean
	 */
	protected function isGuzzle6()
	{
		$composer = json_decode(file_get_contents(base_path('composer.lock')), true);
		
		foreach ($composer['packages'] as $package) {
			if ($package['name'] != 'guzzlehttp/guzzle') {
				continue;
			}

			return preg_match('/6.+/', $package['version']) === 1;
		}

		return false;
	}

	/**
	 * Create the guzzle client.
	 *
	 * @return \GuzzleHttp\Client
	 */
	protected function createGuzzleClient()
	{
		$options = [
			'headers' => [
				'Content-Type' => 'application/json',
			],
		];

		if ($this->caCert) {
			$options['verify']  = $this->caCert;
		}
		if ($this->clientCert) {
			$options['cert']    = $this->clientCert;
		}
		if ($this->clientKey) {
			$options['ssl_key'] = $this->clientKey;
		}
		if ($this->token && file_exists($this->token)) {
			$options['headers']['Authorization'] = 'Bearer ' . file_get_contents($this->token);
		}

		if (!$this->isGuzzle6()){
			return new GuzzleClient([
				'base_url' => $this->master,
				'defaults' => $options,
			]);
		}

		$options['base_uri'] = $this->master;

		return new GuzzleClient($options);
	}

	/**
	 * Get the guzzle client.
	 *
	 * @return \GuzzleHttp\Client|null
	 */
	public function getGuzzleClient()
	{
		return $this->guzzleClient;
	}

	/**
	 * Send a request.
	 *
	 * @param  string  $method
	 * @param  string  $uri
	 * @param  array   $query
	 * @param  array   $body
	 * @param  boolean $namespace
	 * @param  string  $apiVersion
	 * @return array
	 */
	public function sendRequest($method, $uri, $query = [], $body = [], $namespace = true, $apiVersion = null)
	{
		$baseUri = $apiVersion ? '/apis/' . $apiVersion : '/api/' . $this->apiVersion;
		if ($namespace) {
			$baseUri .= '/namespaces/' . $this->namespace;
		}

		$requestUri = $baseUri . $uri;
		$requestOptions = [
			'query' => is_array($query) ? $query : [],
			'body'  => $body,
		];

		if (!$this->isGuzzle6()) {
			try {
				$request = $this->guzzleClient->createRequest($method, $requestUri, $requestOptions);
				$response = $this->guzzleClient->send($request);
			} catch (ClientException $e) {
				throw new BadRequestException($e->getMessage());
			}

			try {
				return $response->json();
			} catch (ParseException $e) {
				return (string) $response->getBody();
			}
		}

		$response = $this->guzzleClient->request($method, $requestUri, $requestOptions);

		$bodyResponse = (string) $response->getBody();
		$jsonResponse = json_decode($bodyResponse, true);

		return is_array($jsonResponse) ? $jsonResponse : $bodyResponse;
	}

	/**
	 * Send a beta request.
	 *
	 * @param  string  $method
	 * @param  string  $uri
	 * @param  mixed   $query
	 * @param  mixed   $body
	 * @param  boolean $namespace
	 * @return array
	 */
	public function sendBetaRequest($method, $uri, $query = [], $body = [], $namespace = true)
	{
		return $this->sendRequest($method, $uri, $query, $body, $namespace, $this->betaApiVersion);
	}

	public function __call($name, $args)
	{
		if (isset($this->classMap[$name])) {
			$class = 'Maclof\Kubernetes\\' . $this->classMap[$name];

			return isset($this->classInstances[$name]) ? $this->classInstances[$name] : new $class($this);
		}

		throw new BadMethodCallException('No client methods exist with the name: ' . $name);
	}
}
