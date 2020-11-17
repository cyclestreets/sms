# CycleStreets SMS client


## About

This repository implements an SMS interface to the CycleStreets API.


## Usage

The user should send a message to the advertised number, in the following format (without brackets):

`cyclestreets <from> to <destination>`

The system will send an SMS back with directions.

To maximise likelihood of successfully parsed addresses, it is recommended to send the request SMS in the format:

`cyclestreets <from streetname>, <town or city> to <destination streetname>, <town or city>`


## Example request

`cyclestreets york street, cambridge to thoday street, cambridge`


## License

Copyright CycleStreets Ltd.

Open source code licensed under the [GNU General Public License version 3 (GPL3)](https://www.gnu.org/licenses/gpl-3.0.en.html).
