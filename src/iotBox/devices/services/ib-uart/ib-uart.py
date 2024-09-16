#!/usr/bin/python3

from os import path, curdir, sep
import serial
import json
import paho.mqtt.client as mqtt


config = None
mqttClient = None
serialPort = None
sendQueue = []

def mqtt_on_connect(client, userdata, flags, rc):
	global config
	#print("==================>>>>>> MQTT Connected with result code "+str(rc))
	client.subscribe(config["mqttTopic"] + "#")

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

def setupSerial():
	global serialPort
	serialPort = serial.Serial(config['serialPort'], baudrate = 115200, timeout = 1)

def loadConfig ():
	global config
	cfgFileName = "/etc/shipard-node/devices/ib-uart.json"
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
	global sendQueue
	read_chars = ""
	counter = 0
	while (1):
		bytes = serialPort.readline()
		read_chars = bytes.decode('utf-8')
		if (len(read_chars)):
			#print(read_chars[0:-1])
			if (read_chars == ""):
				continue
			if (read_chars[0:6] == "[<<<];"):
				doSendValue(read_chars.strip())
				continue
		if (len(sendQueue)):
			data = sendQueue.pop(0)
			serialPort.write(bytearray(data,'ascii'))

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
	global sendQueue
	value = msg.payload.decode('utf-8')
	topicParts = msg.topic.split("/")
	ioPortId = topicParts[len(topicParts) - 1]
	sqItem = ">>>"+ioPortId+" "+value+"\r\n"
	#print("Message received: " + msg.topic + " " + value)
	#print(sqItem)
	sendQueue.append(sqItem)


def main():
	global config
	loadConfig()
	if (config == None):
		print("No config found")
		return
	setupMqtt()
	setupSerial()
	readingLoop()

if __name__ == '__main__':
    main()
