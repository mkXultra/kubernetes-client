<?php namespace Maclof\Kubernetes\Repositories;

use Maclof\Kubernetes\Collections\JobCollection;

class JobRepository extends Repository
{
	protected string $uri = 'jobs';

	protected function createCollection($response): JobCollection
	{
		return new JobCollection($response['items']);
	}

	public function findByName($name): array
	{
		$this->resetParameters();
		return $this->sendRequest('GET', '/' . $this->uri . '/' . $name, [], null, $this->namespace);
	}
}
