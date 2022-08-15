#!/usr/bin/python3

from PIL import Image
import sys

srcFileName = sys.argv[1]
dstFileName = sys.argv[2]

image = Image.open(srcFileName)
resizedImage = image.resize((960,540))
resizedImage.save(dstFileName)
