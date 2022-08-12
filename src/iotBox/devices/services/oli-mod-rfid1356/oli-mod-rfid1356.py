#!/usr/bin/python3

from os import path, curdir, sep
import serial
import json
import paho.mqtt.client as mqtt
import keyboard

config = None
mqttClient = None
serialPort = None

def mqtt_on_connect(client, userdata, flags, rc):
	print("==================>>>>>> MQTT Connected with result code "+str(rc))

def setupMqtt():
	global mqttClient
	mqttClient = mqtt.Client()
	mqttClient.on_connect = mqtt_on_connect
	mqttClient.tls_set()
	mqttClient.connect(config['mqttHost'], config['mqttPort'], 60)
	mqttClient.loop_start()

def setupSerial():
	global serialPort
	serialPort = serial.Serial(config['serialPort'], baudrate = 115200, timeout = 1)


def loadConfig ():
	global config
	cfgFileName = "/etc/shipard-node/devices/oli-mod-rfid1356.json"
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
	last_value = ""
	counter = 0
	while (1):
		bytes = serialPort.readline()
		read_chars = bytes.decode('ASCII')
		if (read_chars == "" or read_chars[0] != "-"):
			continue
		counter += 1
		if (counter > 3):
			last_value = ""
			counter = 0
		if (read_chars == last_value):
			continue
		last_value = read_chars	
		#print (read_chars[1:-1])
		rfidValue = read_chars[1:-2]
		if (1):
			rfidValue = "".join(reversed([rfidValue[i:i+2] for i in range(0, len(rfidValue), 2)]))
		print(rfidValue)
		#mqttClient.publish(config['mqttTopic'], rfidValue)
		keyboard.write(rfidValue, exact = True)
		keyboard.send("enter")


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
