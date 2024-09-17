#!/usr/bin/python3

from os import path, curdir, sep
import time
import json
import paho.mqtt.client as mqtt


config = None
mqttClient = None

states = {
	'cooler': 0,
	'fan': 0,
	'actualTemp': -9999
}


def mqtt_on_connect(client, userdata, flags, rc):
	global config
	print("==================>>>>>> MQTT Connected with result code "+str(rc))
	client.subscribe(config["tempSensorTopic"])

def setupMqtt():
	global config
	global mqttClient
	mqttClient = mqtt.Client()
	mqttClient.on_connect = mqtt_on_connect
	mqttClient.on_message = onMessage
	if (config['mqttUseTLS']):
		mqttClient.tls_set()
	mqttClient.connect(config['mqttHost'], config['mqttPort'], 60)
	mqttClient.loop_start()

def loadConfig ():
	global config
	cfgFileName = "/etc/shipard-node/devices/temp-control.json"
	if (not path.isfile(cfgFileName)):
		return
	try:
		with open(cfgFileName) as json_file:
			config = json.load(json_file)
	except ValueError as e:
		print("config error at", json.last_error_position)

def readingLoop ():
	global config
	global mqttClient
	read_chars = ""
	counter = 0
	while (1):
		time.sleep(1)
		#print ('.')


def doSendValue (data):
	parts = data.split(";")
	if (len(parts) < 3):
		return
	parts.pop(0);
	topic = parts.pop(0);
	value = ';'.join(parts)
	#print (parts)
	mqttClient.publish(topic, value)

def onMessage(client, userdata, msg):
	global config
	value = msg.payload.decode('utf-8')
	print("Message received: " + msg.topic + " " + value)
	if (msg.topic == config['tempSensorTopic']):
		checkTemp(float(value))

def checkTemp(temp):
	global config
	global states
	if (config['tempOn'] > config['tempOff']): # cooler
		print("cooler")
		if (temp > config['tempOn'] and states['cooler'] == 0):
			states['cooler'] = 1
			mqttClient.publish(config['relCoolerTopic'], '1')
			mqttClient.publish(config['relFanTopic'], '1')
			return
		if (temp < config['tempOff'] and states['cooler'] == 1):
			states['cooler'] = 0
			mqttClient.publish(config['relCoolerTopic'], '0')
			mqttClient.publish(config['relFanTopic'], '0')
			return
	else:
		print("heater")

def main():
	global config
	loadConfig()
	if (config == None):
		print("No config found")
		return
	setupMqtt()
	readingLoop()

if __name__ == '__main__':
    main()
