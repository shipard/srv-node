"""
Analyze camera images
"""
import argparse
import os
import json
import urllib
import requests
import io
import time
from datetime import datetime
import tensorflow as tf
from PIL import Image
import numpy as np

import paho.mqtt.client as mqtt

def mqtt_on_connect(client, userdata, flags, rc):
		print("==================>>>>>> MQTT Connected with result code "+str(rc))

modelConfig = {}

class Model(object):
    def __init__(self, model_dir):
        model_path = os.path.realpath(model_dir)
        if not os.path.exists(model_path):
            raise ValueError(f"Exported model folder doesn't exist {model_dir}")
        self.model_path = model_path

        with open(os.path.join(model_path, "signature.json"), "r") as f:
            self.signature = json.load(f)
        self.inputs = self.signature.get("inputs")
        self.outputs = self.signature.get("outputs")

        self.session = None

    def load(self):
        self.cleanup()
        self.session = tf.compat.v1.Session(graph=tf.Graph())
        tf.compat.v1.saved_model.loader.load(sess=self.session, tags=self.signature.get("tags"), export_dir=self.model_path)

    def predict(self, image: Image.Image):
        if self.session is None:
            self.load()
        # get the image width and height
        width, height = image.size
        # center crop image (you can substitute any other method to make a square image, such as just resizing or padding edges with 0)
        if width != height:
            square_size = min(width, height)
            left = (width - square_size) / 2
            top = (height - square_size) / 2
            right = (width + square_size) / 2
            bottom = (height + square_size) / 2
            # Crop the center of the image
            image = image.crop((left, top, right, bottom))
        # now the image is square, resize it to be the right shape for the model input
        if "Image" not in self.inputs:
            raise ValueError("Couldn't find Image in model inputs - please report issue to Lobe!")
        input_width, input_height = self.inputs["Image"]["shape"][1:3]
        if image.width != input_width or image.height != input_height:
            image = image.resize((input_width, input_height))
        # make 0-1 float instead of 0-255 int (that PIL Image loads by default)
        image = np.asarray(image) / 255.0
        feed_dict = {self.inputs["Image"]["name"]: [image]}

        fetches = [(key, output["name"]) for key, output in self.outputs.items()]

        outputs = self.session.run(fetches=[name for _, name in fetches], feed_dict=feed_dict)
        #print (outputs)

        results = {}
        for i, (key, _) in enumerate(fetches):
            val = outputs[i].tolist()[0]
            if isinstance(val, bytes):
                val = val.decode()
            results[key] = val
        return results

    def cleanup(self):
        if self.session is not None:
            self.session.close()
            self.session = None

    def __del__(self):
        self.cleanup()

if __name__ == "__main__":
		parser = argparse.ArgumentParser(description="Analyzing camere stream")
		parser.add_argument("config", help="Path to your model config file.")
		args = parser.parse_args()
		with open(args.config) as f:
				modelConfig = json.load(f)

		mqttClient = mqtt.Client()
		mqttClient.on_connect = mqtt_on_connect
		mqttClient.connect(modelConfig["mqttServerHost"], 1883, 60)
		mqttClient.loop_start()
		model = Model(modelConfig["modelPath"])
		model.load()
		lastState = ""
		lastStateCount = 1
		while True:
				response = requests.get(modelConfig["cameraUrl"])
				image_bytes = io.BytesIO(response.content)
				image = Image.open(image_bytes)
				if image.mode != "RGB":
						image = image.convert("RGB")
				outputs = model.predict(image)
				if outputs["Prediction"] == modelConfig["noActionState"] and lastState == modelConfig["noActionState"]:
						continue
				if outputs["Prediction"] == lastState:
						lastStateCount += 1
				else:
						lastStateCount = 1
						if 'states' in modelConfig and lastState in modelConfig['states']:
								stateDef = modelConfig['states'][lastState]
								mqttClient.publish(modelConfig["mqttTopic"] + stateDef['id'], '0')
				lastState = outputs["Prediction"]
				print(datetime.now(), ": ", outputs["Prediction"])
				if 'states' in modelConfig and outputs["Prediction"] in modelConfig['states']:
						stateDef = modelConfig['states'][outputs["Prediction"]]
						mqttClient.publish(modelConfig["mqttTopic"] + modelConfig["id"], stateDef['id'])
						mqttClient.publish(modelConfig["mqttTopic"] + stateDef['id'], str(lastStateCount))
				else:
					mqttClient.publish(modelConfig["mqttTopic"] + modelConfig["id"], outputs["Prediction"])
				time.sleep(1)
