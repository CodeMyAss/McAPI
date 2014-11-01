<?php

class McAPILatency {

	private $_latency;
	private $_start;
	private $_stop;

	public function __construct() {}

	public function executeAction($action) {

		switch ($action) {
			case McAPILatencyAction::START:
				$this->_start = microtime(true);
				break;
			
			case McAPILatencyAction::STOP:
				$this->_stop = microtime(true);
				break;

            case McAPILatencyAction::ADD:
                $this->_stop += microtime(true);
                break;

			case McAPILatencyAction::CALCULATE:
				$this->_latency = (double) number_format(($this->_stop - $this->_start) * 1000, 0);
				break;

			default:break;
		}

	}

	public function getLatency() {
		return $this->_latency;
	}

}

?>