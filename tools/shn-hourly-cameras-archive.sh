#!/bin/bash

export LC_ALL=C

ROOTDIR=/var/lib/shipard-node
CAMDIR=/var/lib/shipard-node/cameras
PICDIR=/var/lib/shipard-node/cameras/pictures
VIDEOROOT=/var/lib/shipard-node/cameras/video

if [ ! -d "$CAMDIR" ]; then
  exit 0
fi

if [ ! -d "$PICDIR" ]; then
  exit 0
fi

if [ -z "$(ls -A ${VIDEOROOT})" ]; then
    exit 0
fi

CAMERAS=`cd ${PICDIR}; ls -d *`
#echo "cameras: ${CAMERAS}"

FILEMASK=`date +%y%m%d%H -d "1 hour ago"`
FILEMASK2=`date +%y%m%d_%H -d "1 hour ago"`
MAXDATE=`date +"%Y-%m-%d %H:00:00"`
VIDEODIR=`date +%Y/%m/%d -d "1 hour ago"`

DATEDIR=`date +%Y-%m-%d/%H -d "1 hour ago"`
FILEMASK_VIDEO=`date +%Y-%m-%d_%H -d "1 hour ago"`

LOG_FILE_MASK=`date +"%Y-%m-%d"`
LOG_FILE="/var/lib/shipard-node/tmp/cams-log-${LOG_FILE_MASK}"
echo "`date +"%Y-%m-%d_%H-%M-%S"` --- hourly archive ---" >> ${LOG_FILE}

# delete old pictures
echo "`date +"%Y-%m-%d_%H-%M-%S"` begin delete old pictures" >> ${LOG_FILE}
for oneCam in ${CAMERAS}
do
	cd ${PICDIR}/${oneCam}/
	echo "`date +"%Y-%m-%d_%H-%M-%S"`   cd ${PICDIR}/${oneCam}/" >> ${LOG_FILE}
	find . -mmin +60 -type f -delete
	echo "`date +"%Y-%m-%d_%H-%M-%S"`   find . -mmin +60 -type f -delete" >> ${LOG_FILE}
done
echo "`date +"%Y-%m-%d_%H-%M-%S"` end delete old pictures" >> ${LOG_FILE}

# move video files to archive
echo "`date +"%Y-%m-%d_%H-%M-%S"` begin move video to archive" >> ${LOG_FILE}
CAMERAS=`cd ${VIDEOROOT}; ls -d *`
for oneCam in ${CAMERAS}
do
    mkdir -p ${CAMDIR}/archive/video/${DATEDIR}/${oneCam}
    echo "`date +"%Y-%m-%d_%H-%M-%S"`   mkdir -p ${CAMDIR}/archive/video/${DATEDIR}/${oneCam}" >> ${LOG_FILE}
    cd ${VIDEOROOT}/${oneCam}/
    echo "`date +"%Y-%m-%d_%H-%M-%S"`   cd ${VIDEOROOT}/${oneCam}/" >> ${LOG_FILE}
    find . -maxdepth 1 -name "${FILEMASK_VIDEO}*.mp4" | xargs -I {} mv {} ${CAMDIR}/archive/video/${DATEDIR}/${oneCam}/
    echo "`date +"%Y-%m-%d_%H-%M-%S"`   find . -maxdepth 1 -name ${FILEMASK_VIDEO}*.mp4 | xargs -I {} mv {} ${CAMDIR}/archive/video/${DATEDIR}/${oneCam}/" >> ${LOG_FILE}
    #find . -type f -name "${FILEMASK_VIDEO}*.mp4" -print0 | xargs -0r mv -t ${CAMDIR}/archive/video/${DATEDIR}/${oneCam}/
    find . -mmin +240 -type f -delete
    echo "`date +"%Y-%m-%d_%H-%M-%S"`   find . -mmin +240 -type f -delete" >> ${LOG_FILE}
done
echo "`date +"%Y-%m-%d_%H-%M-%S"` end move video to archive" >> ${LOG_FILE}

echo "`date +"%Y-%m-%d_%H-%M-%S"` begin scan video archive" >> ${LOG_FILE}
shipard-node cameras-scan-archive
echo "`date +"%Y-%m-%d_%H-%M-%S"` end scan video archive" >> ${LOG_FILE}

echo "`date +"%Y-%m-%d_%H-%M-%S"` cleanup img cache" >> ${LOG_FILE}
cd /var/lib/shipard-node/imgcache/
find . -mmin +30 -type f -delete

echo "`date +"%Y-%m-%d_%H-%M-%S"` --- hourly archive done ---" >> ${LOG_FILE}
echo "" >> ${LOG_FILE}
echo "" >> ${LOG_FILE}

exit 0
