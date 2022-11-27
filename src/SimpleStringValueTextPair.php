<?php

final class SimpleStringValueTextPair
{

	protected $data;

	public function __construct()
	{
		$this->data = array();
	}

	public function Add( string $value, string $text ) : void
	{
		array_push( $this->data, array(
			'value' => $value,
			'text' => $text
		) );
	}

	public function Generate() : array
	{
		return $this->data;
	}

}
